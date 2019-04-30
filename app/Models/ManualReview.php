<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use App\Models\ApiModel;
use App\Models\ManualReviewGoogle;

class ManualReview extends ApiModel
{
    protected $table = 'manual_review';

    protected $fillable = [
        'person_id',
        'passdate',
    ];

    protected $casts = [
        'passdate' => 'datetime',
    ];

    public static function findForQuery($query)
    {
        $sql = self::select('manual_review.*', 'person.callsign')
                ->join('person', 'manual_review.person_id', 'person.id');

        if (isset($query['year'])) {
            $sql->whereYear('passdate', $query['year']);
        }

        if (isset($query['person_id'])) {
            $sql->where('person_id', $query['person_id']);
        }

        return $sql->get();
    }

    public static function findForPersonYear($personId, $year)
    {
        return self::whereYear('passdate', $year)->where('person_id', $personId)->orderBy('passdate', 'desc')->first();
    }

    /*
     * Find out if the person passed the manual review for the given year
     *
     * 1. look for an existing pass record
     * 2. Run the manual import if does not exists
     * 3. check again for record.
     */

    public static function personPassedForYear($personId, $year, $runImport=true)
    {
        if (ManualReview::existsPersonForYear($personId, $year)) {
            return true;
        }

        if (!$runImport) {
            return false;
        }

        if ($year != date('Y')) {
            // Don't bother running the import for a previous year
            return false;
        }

        ManualReview::importFromGoogle($year);

        // Check again
        return ManualReview::existsPersonForYear($personId, $year);
    }

    public static function importFromGoogle($year)
    {
        $gSheet = new ManualReviewGoogle;
        $gSheet->connect();
        $sheetResults = $gSheet->getResults();

        $rows = self::findForQuery([ 'year' => $year]);

        $alreadyPassed = [
            'test' => 'booga booga',
            'Ranger Radio Handle (imported from Clubhouse)' => 'booga booga'
        ];

        $markAsPassed = [];
        $errors = [];
        $graduated = [];

        foreach ($rows as $row) {
            $alreadyPassed[$row->callsign] = $row;
        }

        $peopleMissing = [];
        foreach ($sheetResults as $entry) {
            if (count($entry) != 2) {
                $errors[] = 'Row in Google sheet missing some data: '.json_encode($entry);
                continue;
            }

            list ($passdate, $callsign) = $entry;

            if (!isset($alreadyPassed[$callsign])) {
                $time = strtotime($passdate);
                if ($year == date('Y', $time)) {
                    $peopleMissing[$callsign] = date('Y-m-d H:i:s', $time);
                }
            }
        }

        if (!empty($peopleMissing)) {
            $people = Person::select('id', 'callsign')
                    ->whereIn('callsign', array_keys($peopleMissing))
                    ->get()
                    ->keyBy('callsign');

            $bulkInsert = [];
            foreach ($peopleMissing as $callsign => $passdate) {
                if (isset($people[$callsign])) {
                    $bulkInsert[] = [ $people[$callsign]->id, $passdate ];
                    $graduated[] = $callsign;
                } else {
                    $errors[] = "Callsign [$callsign] is missing.";
                }
            }

            // Add everyone at one go..
            if (!empty($bulkInsert)) {
                $sql = 'INSERT IGNORE INTO manual_review (person_id, passdate) VALUES (?,?)'.str_repeat(',(?,?)', count($bulkInsert)-1);
                DB::insert($sql, array_flatten($bulkInsert));
            }
        }

        return [
            'errors'    => $errors,
            'new'       => $graduated
        ];
    }

    public static function markPersonAsPassed($personId, $passdate, $year)
    {
        $graduation = strtotime($passdate);
        if ($year != date('Y', $graduation)) {
            // only passing people for a given year
            return true;
        }

        $sqlDate = date('Y-m-d H:i:s', $graduation);
        DB::insert(
            "INSERT IGNORE INTO manual_review (person_id, passdate)
                SELECT ?,? FROM DUAL WHERE NOT EXISTS
                   (SELECT 1 FROM manual_review WHERE person_id=? AND YEAR(passdate)=? LIMIT 1)",
            [ $personId, $sqlDate, $personId, $year]
        );

        return true;
    }

    public static function existsPersonForYear($personId, $year)
    {
        return self::where('person_id', $personId)->whereYear('passdate', $year)->exists();
    }

    public static function countPassedProspectivesAndAlphasForYear($year)
    {
        return self::join('person', 'manual_review.person_id', 'person.id')
                ->whereYear('passdate', $year)
                ->whereIn('person.status', [ 'prospective', 'alpha'])->count();
    }

    public static function prospectiveOrAlphaRankForYear($personId, $year)
    {
        $rows = self::select('person_id')
                ->join('person', 'manual_review.person_id', 'person.id')
                ->whereYear('passdate', $year)
                ->whereIn('person.status', [ 'prospective', 'alpha'])->get();

        foreach ($rows as $index => $row) {
            if ($row->person_id == $personId) {
                return $index + 1;
            }
        }

        return -1;
    }
}
