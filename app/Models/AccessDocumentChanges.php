<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Do not use ApiModel, do not want to audit the audit table.

class AccessDocumentChanges extends Model
{
    protected $table = 'access_document_changes';
    public $timestamps = false;

    protected $fillable = [
        'table_name',
        'record_id',
        'operation',
        'changes',
        'changer_person_id',
    ];

    public static function log($record, $personId, $changes) {
        $row = new AccessDocumentChanges([
            'table_name'    => 'access_document',
            'record_id'     => $record->id,
            'operation'     => 'modify',
            'changes'       => json_encode($changes),
            'changer_person_id' => $personId
        ]);

        $row->save();
    }
}