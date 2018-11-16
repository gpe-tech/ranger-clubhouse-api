<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\AccessDocument;
use App\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;

class AccessDocumentPolicy
{
    use HandlesAuthorization;

    public function before(Person $user) {
        if ($user->hasRole([Role::ADMIN, Role::EDIT_ACCESS_DOCS])) {
            return true;
        }
    }
    /**
     * Determine whether the user can view the AccessDocument.
     */
    public function index(Person $user, $personId)
    {
        return ($user->id == $personId);
    }

    /**
     * A normal user may not create access doucments
     */
    public function create(Person $user)
    {
        return false;
    }

    /**
     * Determine whether the user can view the AccessDocument.
     *
     */
    public function view(Person $user, AccessDocument $accessDocument)
    {
        return ($user->id == $accessDocument->person_id);
    }

    /**
     * Determine whether the user can update the AccessDocument.
     *
     */
    public function update(Person $user, AccessDocument $accessDocument)
    {
        return ($user->id == $accessDocument->person_id);
    }

    /**
     * Determine whether the user can delete the AccessDocument.
     *
     */
    public function delete(Person $user, AccessDocument $accessDocument)
    {
        return ($user->id == $accessDocument->person_id);
    }

    public function storeSOSWAP(Person $user, $personId)
    {
        return ($user->id == $personId);
    }
}