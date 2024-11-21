<?php

namespace Namu\WireChat\Livewire\Info;

use App\Models\User;
use App\Notifications\TestNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
//use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Namu\WireChat\Models\Conversation;
use Namu\WireChat\Models\Message;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithPagination;
use Namu\WireChat\Enums\Actions;
use Namu\WireChat\Enums\ParticipantRole;
use Namu\WireChat\Events\MessageCreated;
use Namu\WireChat\Facades\WireChat;
use Namu\WireChat\Jobs\BroadcastMessage;
use Namu\WireChat\Livewire\Modals\ModalComponent;
use Namu\WireChat\Models\Action;
use Namu\WireChat\Models\Attachment;
use Namu\WireChat\Models\Participant;
use Namu\WireChat\Models\Scopes\WithoutClearedScope;

class Members extends ModalComponent
{

    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public Conversation $conversation;

    public $group;


    public int $totalMembersCount;


    protected $page = 1;
    public $users;
    public $search;
    public $selectedMembers;

    public $participants;
    public $canLoadMore;

    #[Locked]
    public $newTotalCount;

    protected $listeners=[
        'refresh'=>'$refresh'
    ];




    public static function closeModalOnClickAway(): bool
    {

        return true;
    }


    public static function closeModalOnEscape(): bool
    {

        return true;
    }






    public function updatedSearch($value)
    {
        $this->page = 1; // Reset page number when search changes
        $this->participants = collect([]); // Reset to empty collection


        $this->loadParticipants();
    }


    /**
     * Actions
     */

    function sendMessage(Participant $participant)
    {

        abort_unless(auth()->check(), 401);

        $conversation =  auth()->user()->createConversationWith($participant->participantable);

        return redirect()->route('wirechat.chat', [$conversation->id]);
    }



    /**
     * Admin actions */
    function dismissAdmin(Participant $participant)
    {
        $this->toggleAdmin($participant);
    }
    function makeAdmin(Participant $participant)
    {
        $this->toggleAdmin($participant);
    }

    private function toggleAdmin(Participant $participant)
    {

        abort_unless(auth()->check(), 401);

        #abort if user does not belong to conversation
        abort_unless($participant->participantable->belongsToConversation($this->conversation), 403, 'This user does not belong to conversation');

        #abort if user participants is owner
        abort_if($participant->isOwner(), 403, "Owner role cannot be changed");

        #toggle
        if ($participant->isAdmin()) {
            $participant->update(['role' => ParticipantRole::PARTICIPANT]);
        } else {
            $participant->update(['role' => ParticipantRole::ADMIN]);
        }
        $this->dispatch('refresh')->self();

    }

    protected function loadParticipants()
    {
        $searchableFields = WireChat::searchableFields();
        $columnCache = []; // Initialize cache for column checks
        // Check if $this->participants is initialized
        $this->participants = $this->participants ?? collect();

          $additionalParticipants = $this->conversation->participants()
        // ->with('participantable')
        ->when($this->search, function ($query) use ($searchableFields, &$columnCache) {
            $query->whereHas('participantable', function ($query2) use ($searchableFields, &$columnCache) {
                $query2->where(function ($query3) use ($searchableFields, &$columnCache) {
                    $table = $query3->getModel()->getTable();
    
                    foreach ($searchableFields as $field) {
                        if (!isset($columnCache[$table])) {
                            $columnCache[$table] = Schema::getColumnListing($table);
                        }
    
                        if (in_array($field, $columnCache[$table])) {
                            $query3->orWhere($field, 'LIKE', '%' . $this->search . '%');
                        }
                    }
                });
            });
        })
        ->orderByRaw("
            CASE role
                WHEN ? THEN 1
                WHEN ? THEN 2
                WHEN ? THEN 3
                ELSE 4
            END", [
                ParticipantRole::OWNER->value,
                ParticipantRole::ADMIN->value,
                ParticipantRole::PARTICIPANT->value,
        ])
        ->latest('updated_at')
        ->paginate(10, ['*'], 'page', $this->page);
        // Check if cannot load more
        $this->canLoadMore = $additionalParticipants->hasMorePages();


        // Merge current participants with the additional ones
        // Merge current participants with the additional ones and remove duplicates
        $this->participants = $this->participants->merge($additionalParticipants->items())->unique('id');
    }

    
    /*Deleting from group*/
    function removeFromGroup(Participant $participant)  {

        #abort if user does not belong to conversation
        abort_unless($participant->participantable->belongsToConversation($this->conversation), 403, 'This user does not belong to conversation');

        #abort if auth is not admin
        abort_unless(auth()->user()->isAdminIn($this->conversation), 403, 'You do not have permission to perform this action in this group. Only admins can proceed.');

        #abort if user participants is owner
        abort_if($participant->isOwner(), 403, "Owner cannot be removed from group");

        #remove from group
        #Create the 'remove' action record in the actions table
        Action::create([
            'actionable_id' => $participant->id,
            'actionable_type' => Participant::class,
            'actor_id' => auth()->id(),  // The admin who performed the action
            'actor_type' => get_class(auth()->user()),  // Assuming 'User' is the actor model
            'type' => Actions::REMOVED_BY_ADMIN,  // Type of action
        ]);


        #remove from 
         // Remove member if they are already selected
         $this->participants = $this->participants->reject(function ($member) use ($participant) {
            return $member->id == $participant->id && get_class($member) == get_class($participant);
          });


        //subtract one from total members and update chat list
        $this->totalMembersCount =$this->totalMembersCount-1;


        $this->dispatch('participantsCountUpdated',$this->totalMembersCount)->to(Info::class);
      //  $this->dispatch('refresh')->self();

    }


    /**
     * loadmore conversation
     */
    public function loadMore()
    {


        //Check if no more conversations
        if (!$this->canLoadMore) {
            return null;
        }
        // Load the next page
        $this->page++;
        $this->loadParticipants();
    }


    function mount(Conversation $conversation)
    {
        abort_unless(auth()->check(), 401);


        $this->conversation = $conversation->load('group')->loadCount('participants');


        $this->totalMembersCount= $this->conversation->participants_count??0;

        abort_if($this->conversation->isPrivate(), 403, 'This is a private conversation');


        $this->participants = collect();
        $this->loadParticipants();
    }


    public function render()
    {


      //  

        // Pass data to the view
        return view('wirechat::livewire.info.members',[
      'participant'=>$this->conversation->participant(auth()->user())

        ]);
    }
}
