<?php

namespace Rubenwouters\CrmLauncher\Models;

use Illuminate\Database\Eloquent\Model;

class CaseOverview extends Model
{
    /**
     * table name
     * @var string
     */
    protected $table = 'cases';

    //DB relationships
    public function users()
    {
        return $this->belongsToMany('App\User', 'users_cases', 'case_id', 'user_id');
    }

    public function messages()
    {
        return $this->hasMany('Rubenwouters\CrmLauncher\Models\Message', 'case_id');
    }

    public function innerComment()
    {
        return $this->hasMany('Rubenwouters\CrmLauncher\Models\InnerComment', 'case_id');
    }

    public function innerAnswers()
    {
        return $this->hasMany('Rubenwouters\CrmLauncher\Models\InnerAnswer');
    }

    public function summaries()
    {
        return $this->hasMany('Rubenwouters\CrmLauncher\Models\Summary', 'case_id');
    }

    public function contact()
    {
        return $this->belongsTo('Rubenwouters\CrmLauncher\Models\Contact');
    }

    public function answers()
    {
        return $this->hasMany('Rubenwouters\CrmLauncher\Models\Answer');
    }


    /**
     * Get all cases (new, open and closed)
     * @return collection
     */
    public function scopeAllCases($query)
    {
        return $query->orderBy('updated_at', 'DESC')->orderBy('id', 'DESC')->paginate(12);
    }

    /**
     * Get all cases (new, open and closed)
     * @return collection
     */
    public function scopeVisibleCases($query)
    {
        return $query->orderBy('updated_at', 'DESC')->where('status', '!=', '2')->orderBy('id', 'DESC')->paginate(12);
    }

    /**
     * Get private Facebook messages from specific contact
     * @return collection
     */
    public function scopePrivateFbMessages($query, $contact)
    {
        return $query->where('origin', 'Facebook private')->where('contact_id', $contact->id);
    }

    /**
     * Get all new cases
     * @return collection
     */
    public function scopeNewCases($query)
    {
        return $query->where('status', '0')->orderBy('id', 'DESC')->get();
    }

    /**
     * Get all open cases
     * @return collection
     */
    public function scopeOpenCases($query)
    {
        return $query->where('status', '1')->orderBy('id', 'DESC')->get();
    }

    /**
     * Get all closed cases
     * @return collection
     */
    public function scopeClosedCases($query)
    {
        return $query->where('status', '2')->orderBy('id', 'DESC')->get();
    }

    /**
     * Get all pending cases (new & open)
     * @return collection
     */
    public function scopePendingCases($query)
    {
        return $query->where('status', '1')->orWhere('status', '2')->get();
    }

    /**
     * Inserts new case in DB
     * @param  string $type
     * @param  array $message
     * @param  object $contact
     * @return object
     */
    public function createCase($type, $message, $contact)
    {
        $case = new CaseOverview();
        $case->contact_id = $contact->id;

        if ($type == 'twitter_mention') {
            $case->origin = "Twitter mention";
        } else if ($type == 'twitter_direct') {
            $case->origin = "Twitter direct";
        } else if ($type == 'facebook_post') {
            $case->origin = "Facebook post";
        } else if ($type == "facebook_private") {
            $case->origin = 'Facebook private';
        }

        $case->status = 0;
        $case->save();

        return $case;
    }

    /**
     * Changes status of case to "open"
     * @param  object $case
     * @return void
     */
    public function openCase($case)
    {
        $case->status = 1;
        $case->save();
    }
}
