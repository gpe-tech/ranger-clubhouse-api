<?php

namespace App\Http\Controllers;

use App\Lib\Reports\FlakeReport;
use App\Lib\Reports\HQWindowCheckInOutForecastReport;
use App\Lib\Reports\ScheduleByCallsignReport;
use App\Lib\Reports\ScheduleByPositionReport;
use App\Lib\Reports\ShiftCoverageReport;
use App\Lib\Reports\ShiftLeadReport;
use App\Lib\Reports\ShiftSignupsReport;
use App\Models\PersonSlot;
use App\Models\PositionCredit;
use App\Models\Role;
use App\Models\Slot;
use App\Models\SurveyAnswer;
use App\Models\TraineeNote;
use App\Models\TraineeStatus;
use App\Models\TrainerStatus;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;


class SlotController extends ApiController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */

    public function index()
    {
        $this->authorize('index', Slot::class);

        $query = request()->validate([
            'year' => 'required_without:for_rollcall|digits:4',
            'type' => 'sometimes|string',
            'position_id' => 'sometimes|integer',
            'for_rollcall' => 'sometimes|boolean',
        ]);

        $rows = Slot::findForQuery($query);

        $year = $query['year'] ?? current_year();
        if (!$rows->isEmpty()) {
            // Warm the position credit cache
            PositionCredit::warmYearCache($year, array_unique($rows->pluck('position_id')->toArray()));
        }

        return $this->success($rows, null, 'slot');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        $this->authorize('store', Slot::class);

        $slot = new Slot;
        $this->fromRest($slot);

        if (!$this->validateRestrictions($slot)) {
            return $this->restError($slot);
        }

        if (!$slot->save()) {
            return $this->restError($slot);
        }

        // Return the position & trainer_slot info
        $slot->loadRelationships();

        return $this->success($slot);
    }

    /**
     * Display the specified resource.
     *
     * @return JsonResponse
     */
    public function show(Slot $slot)
    {
        return $this->success($slot);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Slot $slot
     * @return JsonResponse
     */
    public function update(Slot $slot)
    {
        $this->authorize('update', Slot::class);
        $this->fromRest($slot);

        if (!$this->validateRestrictions($slot)) {
            return $this->restError($slot);
        }

        if (!$slot->save()) {
            return $this->restError($slot);
        }

        // In case position or trainer_slot changed.
        $slot->loadRelationships();

        return $this->success($slot);
    }

    public function bulkUpdate(Request $request)
    {
        $this->authorize('update', Slot::class);

        $params = request()->validate([
            'ids' => 'required|array',
            'attributes' => 'required|array'
        ]);

        $attributes = $params['attributes'];
        $slots = Slot::whereIn('id', $params['ids'])->get();

        DB::transaction(function () use ($slots, $attributes) {
            foreach ($slots as $slot) {
                $slot->fill($attributes);
                $slot->auditReason = 'bulk update';
                $slot->save();
            }
        });

        return $this->success($slots, null, 'slots');
    }

    /**
     * Copy slots in bulk.  This supports two main use cases:
     * #1: Copy with a date delta, e.g. add 364 days (to align with Labor Day) to several positions in last year's schedule.
     *     Set `deltaDays`, `deltaHours`, `deltaMinutes` as appropriate.
     * #2: Create a schedule for a new position based on the schedule for another position.
     *     Set `newPositionId` and optionally `attributes` to override specific properties,
     *     e.g. set `attributes.max` to 1 for all new slots.
     * In both cases, the source slot IDs must be set in the `ids` parameter.
     * The slots will be created as inactive unless the `activate` parameter is true.
     */
    public function copy()
    {
        $this->authorize('store', Slot::class);
        $params = request()->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer',
            'deltaDays' => 'sometimes|integer',
            'deltaHours' => 'sometimes|integer',
            'deltaMinutes' => 'sometimes|integer',
            'newPositionId' => 'sometimes|exists:position,id',
            'activate' => 'sometimes|boolean',
            'attributes' => 'sometimes|array',
        ]);

        $activate = $params['activate'] ?? false;
        $attributes = $params['attributes'] ?? array();
        $deltaDays = $params['deltaDays'] ?? 0;
        $deltaHours = $params['deltaHours'] ?? 0;
        $deltaMinutes = $params['deltaMinutes'] ?? 0;
        if ($deltaDays != 0 || $deltaHours != 0 || $deltaMinutes != 0) {
            $delta = "$deltaDays day $deltaHours hour $deltaMinutes minute";
        } else {
            $delta = null;
        }
        $position = $params['newPositionId'] ?? null;
        if (!$delta && !$position) {
            return $this->restError('Must specify new position or a day/time delta');
        }
        $sourceSlots = Slot::whereIn('id', $params['ids'])->get();
        $results = array();
        try {
            DB::transaction(function () use ($sourceSlots, $delta, $position, $activate, $attributes, &$results) {
                foreach ($sourceSlots as $source) {
                    if ($source->training_id || $source->trainer_slot_id || $source->trainee_slot_id) {
                        $title = $source->position->title;
                        throw new UnexpectedValueException(
                            "Clubhouse server doesn't yet know how to bulk-copy training or mentor/mentee shift pairs:"
                            . " ${title}: {$source->description}: {$source->begins}");
                    }
                    $target = $source->replicate();
                    $target->fill($attributes);
                    if ($delta) {
                        $target->begins = $source->begins->modify($delta);
                        $target->ends = $source->ends->modify($delta);
                    }
                    if (!empty($position)) {
                        $target->position_id = $position;
                    }
                    $target->signed_up = 0;
                    $target->active = $activate;
                    $target->auditReason = 'slot copy';
                    $target->saveOrThrow();
                    array_push($results, $target);
                }
            });
        } catch (UnexpectedValueException $e) {
            return $this->restError($e->getMessage(), 400);
        }
        return $this->success($results, null, 'slot');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Slot $slot
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function destroy(Slot $slot)
    {
        $this->authorize('delete', Slot::class);

        PersonSlot::deleteForSlot($slot->id);
        TraineeStatus::deleteForSlot($slot->id);
        TrainerStatus::deleteForSlot($slot->id);
        TraineeNote::deleteForSlot($slot->id);
        SurveyAnswer::deleteForSlot($slot->id);

        $slot->delete();

        return $this->restDeleteSuccess();
    }

    /**
     * Return people signed up for a given Slot
     * @return JsonResponse
     */

    public function people(Slot $slot)
    {
        $params = request()->validate([
            'is_onduty' => 'sometimes|boolean',
            'include_photo' => 'sometimes|boolean'
        ]);

        return response()->json([
            'people' => Slot::findSignUps($slot->id, $params['is_onduty'] ?? false, $params['include_photo'] ?? false)
        ]);
    }

    /**
     * Return how many years the slots span
     * @return JsonResponse
     */

    public function years()
    {
        return response()->json(['years' => Slot::findYears()]);
    }

    /**
     * Partially validate a slot based on restrictions
     *
     * - If the slot is time restricted (begins within pre-event period, and not
     * an approved position, then only an Admin may be allowed to create or update.
     */

    private function validateRestrictions(Slot $slot)
    {
        if (!$slot->isPreEventRestricted()) {
            // Either falls outside the pre-event period, or has an approved position
            return true;
        }

        if ($this->userHasRole(Role::ADMIN)) {
            // Bow before your slot master!
            return true;
        }

        $slot->addError('begins', 'Slot is a non-training position and the start time falls within the pre-event period. Action requires Admin privileges.');
        return false;
    }

    /*
     * Report on the Dirt Shifts - used for the shift Lead Report
     */

    public function dirtShiftTimes()
    {
        $this->authorize('report', Slot::class);

        $params = request()->validate([
            'year' => 'required|integer'
        ]);

        return response()->json(['shifts' => Slot::retrieveDirtTimes($params['year'])]);
    }

    /*
     * Shift Lead report on sign ups
     */

    public function shiftLeadReport()
    {
        $this->authorize('report', Slot::class);

        $params = request()->validate([
            'shift_start' => 'required|date',
            'shift_duration' => 'required|integer'
        ]);

        return response()->json(ShiftLeadReport::execute(Carbon::parse($params['shift_start']), $params['shift_duration']));
    }

    /*
     * HQ Check In/Out Forecast report
     */

    public function hqForecastReport()
    {
        $this->authorize('report', Slot::class);

        $params = request()->validate([
            'year' => 'required|integer',
            'interval' => 'required|integer',
        ]);

        return response()->json(HQWindowCheckInOutForecastReport::execute($params['year'], $params['interval']));
    }

    /*
     * Shift Coverage Report
     */

    public function shiftCoverageReport()
    {
        $this->authorize('report', Slot::class);

        $params = request()->validate([
            'year' => 'required|integer',
            'type' => 'required|string'
        ]);

        return response()->json(ShiftCoverageReport::execute($params['year'], $params['type']));
    }

    /*
     * Shift Sign Up Report
     */

    public function shiftSignUpsReport()
    {
        $this->authorize('report', Slot::class);

        $year = $this->getYear();

        return response()->json(['positions' => ShiftSignupsReport::execute($year)]);
    }

    /*
     * Schedule By Position Report
     */

    public function positionScheduleReport()
    {
        $this->authorize('report', Slot::class);

        $year = $this->getYear();

        return response()->json(ScheduleByPositionReport::execute($year, $this->userCanViewEmail()));
    }

    /*
     * Schedule By Callsign Report
     */

    public function callsignScheduleReport()
    {
        $this->authorize('report', Slot::class);

        $year = $this->getYear();

        return response()->json(ScheduleByCallsignReport::execute($year));
    }

    /*
     * Flake Report
     */

    public function flakeReport()
    {
        $this->authorize('report', Slot::class);

        $params = request()->validate([
            'date' => 'sometimes|date'
        ]);

        $date = $params['date'] ?? now();

        return response()->json([
            'positions' => FlakeReport::execute($date),
            'date' => (string)$date,
        ]);
    }

}
