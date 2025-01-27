<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Bmid extends ApiModel
{
    protected $table = 'bmid';
    protected $auditModel = true;

    const MEALS_ALL = 'all';
    const MEALS_EVENT = 'event';
    const MEALS_EVENT_PLUS_POST = 'event+post';
    const MEALS_POST = 'post';
    const MEALS_PRE = 'pre';
    const MEALS_PRE_PLUS_EVENT = 'pre+event';
    const MEALS_PRE_PLUS_POST = 'pre+post';

    const MEALS_TYPES = [
        self::MEALS_ALL,
        self::MEALS_EVENT,
        self::MEALS_EVENT_PLUS_POST,
        self::MEALS_POST,
        self::MEALS_PRE,
        self::MEALS_PRE_PLUS_EVENT,
        self::MEALS_PRE_PLUS_POST
    ];

    // BMID is being prepped
    const IN_PREP = 'in_prep';
    // Ready to be sent off to be printed
    const READY_TO_PRINT = 'ready_to_print';
    // BMID was changed (name, photos, titles, etc.) and needs to be reprinted
    const READY_TO_REPRINT_CHANGE = 'ready_to_reprint_changed';
    // BMID was lost and a new one issued
    const READY_TO_REPRINT_LOST = 'ready_to_reprint_lost';

    // BMID has issues, do not print.
    const ISSUES = 'issues';

    // Person is not rangering this year (common) or another reason.
    const DO_NOT_PRINT = 'do_not_print';

    // BMID was submitted
    const SUBMITTED = 'submitted';

    const READY_TO_PRINT_STATUSES = [
        self::IN_PREP,
        self::READY_TO_PRINT,
        self::READY_TO_REPRINT_CHANGE,
        self::READY_TO_REPRINT_LOST,
    ];

    const ALLOWED_PERSON_STATUSES = [
        Person::ACTIVE,
        Person::INACTIVE,
        Person::INACTIVE_EXTENSION,
        Person::RETIRED,
        Person::ALPHA,
        Person::PROSPECTIVE
    ];

    const BADGE_TITLES = [
        // Title 1
        Position::RSC_SHIFT_LEAD => ['title1', 'Shift Lead'],
        Position::DEPARTMENT_MANAGER => ['title1', 'Department Manager'],
        Position::OPERATIONS_MANAGER => ['title1', 'Operations Manager'],
        Position::OOD => ['title1', 'Officer of the Day'],
        // Title 2
        Position::LEAL => ['title2', 'LEAL'],
        // Title 3
        Position::DOUBLE_OH_7 => ['title3', '007']
    ];

    const PERSON_WITH = 'person:id,callsign,status,first_name,last_name,email,bpguid';

    protected $wap;

    protected $access_any_time = false;
    protected $access_date = null;

    protected $has_signups = false;
    protected $org_vehicle_insurance = false;

    protected $want_meals = '';
    protected $want_showers = false;

    protected $fillable = [
        'person_id',
        'year',
        'status',
        'title1',
        'title2',
        'title3',
        'team',
        'showers',
        'meals',
        'batch',
        'notes',

        // pseudo-columns
        'access_date',
        'access_any_time',
    ];

    protected $guarded = [
        'create_datetime',
        'modified_datetime'
    ];

    protected $attributes = [
        'showers' => false,
        'meals' => null,
    ];

    protected $casts = [
        'showers' => 'bool',
        'org_vehicle_insurance' => 'bool',
        'create_datetime' => 'datetime',
        'modified_datetime' => 'datetime',
        'access_date' => 'datetime:Y-m-d',
        'access_any_time' => 'bool',
        'want_showers' => 'bool',
    ];

    protected $appends = [
        'access_any_time',
        'access_date',
        'has_signups',
        'org_vehicle_insurance',
        'want_meals',
        'want_showers',
        'wap_id',
        'wap_status',
        'wap_type',
    ];

    public static function boot()
    {
        parent::boot();

        self::creating(function ($model) {
            if (empty($model->status)) {
                $model->status = self::IN_PREP;
            }
        });

        self::saved(function ($model) {
            $model->updateWap();
        });

        self::created(function ($model) {
            $model->updateWap();
        });
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public static function find($id)
    {
        $row = self::where('id', $id)->first();
        if ($row) {
            $row->loadRelationships();
        }

        return $row;
    }

    public function loadRelationships()
    {
        self::bulkLoadRelationships(new EloquentCollection([$this]), [$this->person_id]);
    }

    public function setWap($wap)
    {
        $this->access_date = $wap->access_date;
        $this->access_any_time = $wap->access_any_time;
        $this->wap = $wap;
    }

    public static function findOrFail($id)
    {
        $row = self::where('id', $id)->firstOrFail();
        if ($row) {
            $row->loadRelationships();
        }

        return $row;
    }

    public static function findForPersonYear($personId, $year)
    {
        return self::where('person_id', $personId)->where('year', $year)->first();
    }

    public static function findForPersonManage($personId, $year)
    {
        $rows = self::findForPersonIds($year, [$personId]);
        return $rows[0];
    }

    public static function findForPersonIds($year, $personIds)
    {
        if (empty($personIds)) {
            return [];
        }

        // Bulk look up
        $bmids = Bmid::where('year', $year)->whereIn('person_id', $personIds)->get();
        $bmidsByPerson = $bmids->keyBy('person_id');

        // Figure out which people do not have BMIDs yet.
        foreach ($personIds as $personId) {
            if (!$bmidsByPerson->has($personId)) {
                $bmid = new Bmid([
                    'person_id' => $personId,
                    'year' => $year,
                    'status' => self::IN_PREP
                ]);

                $bmids->push($bmid);
                $bmidsByPerson[$personId] = $bmid;
            }
        }

        self::bulkLoadRelationships($bmids, $personIds);

        $bmids = $bmids->sortBy(
            fn($bmid, $key) => ($bmid->person ? $bmid->person->callsign : ""),
            SORT_NATURAL | SORT_FLAG_CASE
        )->values();

        return $bmids;
    }

    public static function bulkLoadRelationships($bmids, $personIds)
    {
        $year = current_year();

        // Populate all the BMIDs with people..
        $bmids->load([self::PERSON_WITH]);

        // Load up the org insurance flags
        $personEvents = PersonEvent::findAllForIdsYear($personIds, $year)->keyBy('person_id');
        foreach ($bmids as $bmid) {
            $event = $personEvents->get($bmid->person_id);
            if ($event) {
                $bmid->org_vehicle_insurance = $event->org_vehicle_insurance;
            }
        }

        // Set the WAPs
        $waps = AccessDocument::findWAPForPersonIds($personIds);
        $bmidsByPerson = $bmids->keyBy('person_id');
        foreach ($waps as $personId => $wap) {
            $bmidsByPerson[$personId]->setWap($wap);
        }

        // Figure out who has signed up for the year.
        $ids = DB::table('person')
            ->select('id')
            ->whereIn('id', $personIds)
            ->whereRaw("EXISTS (SELECT 1 FROM person_slot JOIN slot ON person_slot.slot_id=slot.id WHERE person.id=person_slot.person_id AND YEAR(slot.begins)=$year LIMIT 1)")
            ->get()
            ->pluck('id');

        foreach ($ids as $id) {
            $bmidsByPerson[$id]->has_signups = true;
        }

        // The provisions are special - by default, the items are opt-out so treat an item qualified as
        // the same as claimed.

        $itemsByPersonId = AccessDocument::whereIn('person_id', $bmids->pluck('person_id'))
            ->whereIn('status', [AccessDocument::QUALIFIED, AccessDocument::CLAIMED, AccessDocument::SUBMITTED])
            ->whereIn('type', [AccessDocument::ALL_EAT_PASS, AccessDocument::EVENT_EAT_PASS, AccessDocument::WET_SPOT])
            ->get()
            ->groupBy('person_id');

        foreach ($bmids as $bmid) {
            $items = $itemsByPersonId->get($bmid->person_id);
            if (!$items) {
                continue;
            }

            foreach ($items as $item) {
                switch ($item->type) {
                    case AccessDocument::ALL_EAT_PASS:
                        $bmid->want_meals = self::MEALS_ALL;
                        break;
                    case AccessDocument::EVENT_EAT_PASS:
                        $bmid->want_meals = self::MEALS_EVENT;
                        break;
                    case AccessDocument::WET_SPOT:
                        $bmid->want_showers = true;
                        break;
                }
            }
        }
    }

    public static function firstOrNewForPersonYear($personId, $year)
    {
        $row = self::firstOrNew(['person_id' => $personId, 'year' => $year]);
        $row->loadRelationships();

        return $row;
    }

    public static function findForQuery($query)
    {
        $sql = self::query();

        $year = $query['year'] ?? null;
        if ($year) {
            $sql->where('year', $year);
        }

        $bmids = $sql->with(['person:id,callsign,email'])->get();

        self::bulkLoadRelationships($bmids, $bmids->pluck('person_id')->toArray());

        return $bmids;
    }


    public function updateWap()
    {
        AccessDocument::updateWAPsForPerson($this->person_id, $this->access_date, $this->access_any_time, 'set via BMID update');

        $wap = $this->wap;
        if ($wap) {
            $wap->refresh();
            $this->setWap($wap);
        }
    }

    public function setTitle1Attribute($value)
    {
        $this->attributes['title1'] = $value ?: null;
    }

    public function setTitle2Attribute($value)
    {
        $this->attributes['title2'] = $value ?: null;
    }

    public function setTitle3Attribute($value)
    {
        $this->attributes['title3'] = $value ?: null;
    }

    public function setMealsAttribute($value)
    {
        $this->attributes['meals'] = $value ?: null;
    }

    public function setTeamAttribute($value)
    {
        $this->attributes['team'] = $value ?: null;
    }


    public function setAccessDateAttribute($value)
    {
        $this->access_date = $value;
    }

    public function getAccessDateAttribute()
    {
        return (string)$this->access_date;
    }

    public function setAccessAnyTimeAttribute($value)
    {
        $this->access_any_time = $value;
    }

    public function getAccessAnyTimeAttribute()
    {
        return $this->access_any_time;
    }

    public function setOrgVehicleInsuranceAttribute($value)
    {
        $this->org_vehicle_insurance = $value;
    }

    public function getOrgVehicleInsuranceAttribute()
    {
        return $this->org_vehicle_insurance;
    }

    public function getWapIdAttribute()
    {
        return $this->wap ? $this->wap->id : null;
    }

    public function getWapStatusAttribute()
    {
        return $this->wap ? $this->wap->status : null;
    }

    public function getWapTypeAttribute()
    {
        return $this->wap ? $this->wap->type : null;
    }

    public function getHasSignupsAttribute()
    {
        return $this->has_signups;
    }

    public function getWantMealsAttribute()
    {
        return $this->want_meals;
    }

    public function getWantShowersAttribute()
    {
        return $this->want_showers;
    }

    /**
     * Is the BMID printable (both person & BMID have to be an acceptable status)
     *
     * @return bool
     */
    public function isPrintable(): bool
    {
        if (!$this->person || !in_array($this->person->status, self::ALLOWED_PERSON_STATUSES)) {
            return false;
        }

        if (!in_array($this->status, self::READY_TO_PRINT_STATUSES)) {
            return false;
        }

        return true;
    }

    /**
     * Append to the notes with timestamp and callsign.
     *
     * @param string $notes
     */

    public function appendNotes(string $notes)
    {
        $date = date('n/j/y G:i:s');
        $callsign = Auth::check() ? Auth::user()->callsign : '(unknown)';
        $this->notes = "$date $callsign: $notes\n{$this->notes}";
    }

    /**
     * Builds a "meal matrix" indicating which weeks the person can
     * have meals. This is a union between what has been set by the
     * BMID administrator, and what the person claimed.
     *
     * @return array
     */

    public function buildMealsMatrix(): array
    {
        if ($this->meals == self::MEALS_ALL || $this->want_meals == self::MEALS_ALL) {
            return [self::MEALS_PRE => true, self::MEALS_EVENT => true, self::MEALS_POST => true];
        }

        $matrix = [];
        foreach (explode('+', $this->meals) as $week) {
            $matrix[$week] = true;
        }

        if (!empty($this->want_meals)) {
            $matrix[$this->want_meals] = true;
        }

        return $matrix;
    }

    public function effectiveMeals(): string
    {
        $meals = $this->buildMealsMatrix();
        return count($meals) == 3 ? Bmid::MEALS_ALL : implode('+', array_keys($meals));
    }
}
