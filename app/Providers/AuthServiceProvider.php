<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

use App\Models\AccessDocument;
use App\Models\AccessDocumentDelivery;
use App\Models\ActionLog;
use App\Models\Alert;
use App\Models\AlertPerson;
use App\Models\Asset;
use App\Models\AssetAttachment;
use App\Models\AssetPerson;
use App\Models\Bmid;
use App\Models\Broadcast;
use App\Models\ErrorLog;
use App\Models\EventDate;
use App\Models\ManualReview;
use App\Models\Person;
use App\Models\PersonMentor;
use App\Models\PersonMessage;
use App\Models\Position;
use App\Models\PositionCredit;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Slot;
use App\Models\Setting;
use App\Models\Timesheet;
use App\Models\TimesheetMissing;
use App\Models\Training;
use App\Models\TrainingSession;

use App\Policies\AccessDocumentDeliveryPolicy;
use App\Policies\AccessDocumentPolicy;
use App\Policies\ActionLogPolicy;
use App\Policies\AlertPersonPolicy;
use App\Policies\AlertPolicy;
use App\Policies\AssetPersonPolicy;
use App\Policies\AssetPolicy;
use App\Policies\AssetAttachmentPolicy;
use App\Policies\BmidPolicy;
use App\Policies\BroadcastPolicy;
use App\Policies\ErrorLogPolicy;
use App\Policies\EventDatePolicy;
use App\Policies\ManualReviewPolicy;
use App\Policies\PersonMentorPolicy;
use App\Policies\PersonMessagePolicy;
use App\Policies\PersonPolicy;
use App\Policies\PositionPolicy;
use App\Policies\PositionCreditPolicy;
use App\Policies\RolePolicy;
use App\Policies\SchedulePolicy;
use App\Policies\SettingPolicy;
use App\Policies\SlotPolicy;
use App\Policies\TimesheetMissingPolicy;
use App\Policies\TimesheetPolicy;
use App\Policies\TrainingPolicy;
use App\Policies\TrainingSessionPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        AccessDocument::class => AccessDocumentPolicy::class,
        AccessDocumentDelivery::class => AccessDocumentDeliveryPolicy::class,
        ActionLog::class => ActionLogPolicy::class,
        Alert::class => AlertPolicy::class,
        AlertPerson::class => AlertPersonPolicy::class,
        Asset::class  => AssetPolicy::class,
        AssetAttachment::class  => AssetAttachmentPolicy::class,
        AssetPerson::class => AssetPersonPolicy::class,
        Bmid::class => BmidPolicy::class,
        Broadcast::class => BroadcastPolicy::class,
        ErrorLog::class => ErrorLogPolicy::class,
        EventDate::class => EventDatePolicy::class,
        ManualReview::class => ManualReviewPolicy::class,
        Person::class => PersonPolicy::class,
        PersonMentor::class => PersonMentorPolicy::class,
        PersonMessage::class => PersonMessagePolicy::class,
        Position::class => PositionPolicy::class,
        PositionCredit::class => PositionCreditPolicy::class,
        Role::class => RolePolicy::class,
        Schedule::class => SchedulePolicy::class,
        Setting::class => SettingPolicy::class,
        Slot::class => SlotPolicy::class,
        Timesheet::class => TimesheetPolicy::class,
        TimesheetMissing::class => TimesheetMissingPolicy::class,
        Training::class => TrainingPolicy::class,
        TrainingSession::class => TrainingSessionPolicy::class,

        'App\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::resource('person', 'PersonPolicy');
    }
}
