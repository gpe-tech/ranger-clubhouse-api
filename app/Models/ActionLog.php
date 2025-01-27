<?php

namespace App\Models;

use App\Models\Person;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use DateTimeInterface;

class ActionLog extends Model
{
    protected $table = 'action_logs';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'data' => 'array'
    ];

    // created_at is handled by the database itself
    public $timestamps = false;

    const PAGE_SIZE_DEFAULT = 50;

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function target_person()
    {
        return $this->belongsTo(Person::class);
    }

    public static function findForQuery($query, $redactData)
    {
        $personId = $query['person_id'] ?? null;
        $page = $query['page'] ?? 1;
        $pageSize = $query['page_size'] ?? self::PAGE_SIZE_DEFAULT;
        $events = $query['events'] ?? [];
        $sort = $query['sort'] ?? 'desc';
        $startTime = $query['start_time'] ?? null;
        $endTime = $query['end_time'] ?? null;
        $lastDay = $query['lastday'] ?? false;

        $sql = self::query();

        if ($personId) {
            $sql->where(function ($q) use ($personId) {
                $q->where('person_id', $personId)
                    ->orWhere('target_person_id', $personId);
            });
        }

        if (!empty($events)) {
            $exactEvents = [];
            $likeEvents = [];

            foreach ($events as $event) {
                if (strpos($event, '%') === false) {
                    $exactEvents[] = $event;
                } else {
                    $likeEvents[] = $event;
                }
            }

            $sql->where(function ($query) use ($exactEvents, $likeEvents) {
                if (!empty($exactEvents)) {
                    $query->orWhereIn('event', $exactEvents);
                }

                if (!empty($likeEvents)) {
                    foreach ($likeEvents as $event) {
                        $query->orWhere('event', 'LIKE', $event);
                    }
                }
            });
        }

        if ($startTime) {
            $sql->where('created_at', '>=', $startTime);
        }

        if ($endTime) {
            $sql->where('created_at', '<=', $endTime);
        }

        if ($lastDay) {
            $sql->whereRaw('created_at >= ?', [ now()->subHours(24) ]);
        }

        // How many total for the query
        $total = $sql->count();

        if (!$total) {
            // Nada.. don't bother
            return ['action_logs' => [], 'meta' => ['page' => 0, 'total' => 0, 'total_pages' => 0]];
        }

        // Results sort 'asc' or 'desc'
        $sortOrder = ($sort == 'asc' ? 'asc' : 'desc');
        $sql->orderBy('created_at', $sortOrder);
        $sql->orderBy('id', $sortOrder);

        // Figure out pagination
        $page = $page - 1;
        if ($page < 0) {
            $page = 0;
        }

        $sql->offset($page * $pageSize)->limit($pageSize);

        // .. and go get it!
        $rows = $sql->with(['person:id,callsign', 'target_person:id,callsign'])->get();

        foreach ($rows as $row) {
            $data = $row->data;

            if (empty($row->data)) {
                continue;
            }

            if (isset($data['slot_id'])) {
                $row->slot = Slot::where('id', $data['slot_id'])->with('position:id,title')->first();
            }

            if (isset($data['enrolled_slot_ids']) && is_array($data['enrolled_slot_ids'])) {
                $row->enrolled_slots = Slot::whereIn('id', $data['enrolled_slot_ids'])->with('position:id,title')->first();
            }

            if (isset($data['position_ids']) && is_array($data['position_ids'])) {
                $row->positions = Position::whereIn('id', $data['position_ids'])->orderBy('title')->get(['id', 'title']);
            }

            if (isset($data['position_id'])) {
                $row->position = Position::where('id', $data['position_id'])->first();
            }

            if (isset($data['role_ids']) && is_array($data['role_ids'])) {
                $row->roles = Role::whereIn('id', array_values($data['role_ids']))->orderBy('title')->get(['id', 'title']);
            }

            if ($redactData) {
                $row->data = null;
            }
        }

        return [
            'action_logs' => $rows,
            'meta' => [
                'total' => $total,
                'total_pages' => (int)(($total + ($pageSize - 1)) / $pageSize),
                'page_size' => $pageSize,
                'page' => $page + 1,
            ]
        ];
    }

    public static function record($person, $event, $message, $data = null, $targetPersonId = null)
    {
        $log = new ActionLog;
        $log->event = $event;
        $log->person_id = $person ? $person->id : null;
        $log->message = $message ?? '';
        $log->target_person_id = $targetPersonId;

        if ($data) {
            $log->data = $data;
        }

        $log->created_at = now();
        $log->save();
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}
