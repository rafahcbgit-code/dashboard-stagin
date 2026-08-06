<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Propaganistas\LaravelPhone\PhoneNumber;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Contact extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];
    protected $appends = ['full_name', 'formatted_phone_number'];
    protected $dates = ['deleted_at'];
    public $timestamps = false;
    protected static array $phoneMaskingEnabledCache = [];
    protected static array $viewerRoleCache = [];

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getAllContacts($organizationId, $searchTerm)
    {
        return $this->with('contactGroups')
            ->where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->where(function ($query) use ($searchTerm) {
                $query->where('contacts.first_name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('contacts.last_name', 'like', '%' . $searchTerm . '%')

                    // Split the search term into parts and check for matches in both columns
                    ->orWhere(function ($subQuery) use ($searchTerm) {
                        $searchParts = explode(' ', $searchTerm);
                        if (count($searchParts) > 1) {
                            $subQuery->where('contacts.first_name', 'like', '%' . $searchParts[0] . '%')
                                ->where('contacts.last_name', 'like', '%' . $searchParts[1] . '%');
                        }
                    })

                    // Match phone or email
                    ->orWhere('contacts.phone', 'like', '%' . $searchTerm . '%')
                    ->orWhere('contacts.email', 'like', '%' . $searchTerm . '%');
            })
            ->orderByDesc('is_favorite')
            ->latest()
            ->orderBy('id')
            ->paginate(10);
    }

    public function getAllContactGroups($organizationId)
    {
        return ContactGroup::where('organization_id', $organizationId)->whereNull('deleted_at')->get();
    }

    public function countContacts($organizationId)
    {
        return $this->where('organization_id', $organizationId)->whereNull('deleted_at')->count();
    }

    public function contactGroups()
    {
        return $this->belongsToMany(ContactGroup::class, 'contact_contact_group', 'contact_id', 'contact_group_id')
            ->using(ContactContactGroup::class)
            ->withTimestamps();
    }

    public function notes()
    {
        return $this->hasMany(ChatNote::class, 'contact_id')->orderBy('created_at', 'desc');
    }

    public function chats()
    {
        return $this->hasMany(Chat::class, 'contact_id')->orderBy('created_at', 'asc');
    }

    public function lastChat()
    {
        return $this->hasOne(Chat::class, 'contact_id')->with('media')->latestOfMany('created_at');
    }

    public function lastInboundChat()
    {
        return $this->hasOne(Chat::class, 'contact_id')
            ->where('type', 'inbound')
            ->with('media')
            ->latestOfMany('created_at');
    }

    public function unreadInboundChats()
    {
        return $this->hasMany(Chat::class, 'contact_id')
            ->where('type', 'inbound')
            ->where('is_read', 0)
            ->whereNull('deleted_at');
    }

    public function chatLogs()
    {
        return $this->hasMany(ChatLog::class);
    }

    public function contactsWithChats($organizationId, $searchTerm = null, $ticketingActive = false, $ticketState = null, $sortDirection = 'asc', $role = 'owner', $allowAgentsViewAllChats = true, $labelFilters = [], $assignedToMe = false, $assignedToFilter = null, $unreadOnly = false, ?int $total = null, bool $awaitingReply = false)
    {
        $needsTicketJoin = $ticketingActive && (
            $assignedToFilter !== null
            || ($assignedToMe && $role === 'agent')
            || $ticketState === 'unassigned'
            || ($ticketState !== null && $ticketState !== 'all')
            || ($role === 'agent' && !$allowAgentsViewAllChats)
        );

        $query = $this->newQuery()
            ->where('contacts.organization_id', $organizationId)
            ->whereNotNull('contacts.latest_chat_created_at')
            ->with(['lastChat', 'lastInboundChat', 'latestAssignedUser', 'labels', 'ticket'])
            ->whereNull('contacts.deleted_at')
            ->select('contacts.*');

        if ($needsTicketJoin) {
            $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id')
                ->distinct();
        }

        // Apply label filtering
        if (!empty($labelFilters)) {
            $query->whereHas('labels', function ($q) use ($labelFilters) {
                $q->whereIn('labels.id', $labelFilters);
            });
        }

        // Apply unread messages filter
        if ($unreadOnly) {
            $query->whereHas('chats', function ($q) {
                $q->where('type', 'inbound')
                  ->where('is_read', 0)
                  ->whereNull('deleted_at');
            });
        }

        // Only include conversations whose latest message was received from the contact.
        if ($awaitingReply) {
            $query->whereHas('lastChat', function ($q) {
                $q->where('type', 'inbound');
            });
        }

        // Apply ticketing conditions only when a ticket filter is needed.
        if ($needsTicketJoin) {
            // Apply assigned_to filter if provided
            if ($assignedToFilter !== null) {
                $query->where('chat_tickets.assigned_to', $assignedToFilter);
            } elseif ($assignedToMe && $role === 'agent') {
                $query->where('chat_tickets.assigned_to', auth()->user()->id);
            }

            if ($ticketState === 'unassigned') {
                $query->whereNull('chat_tickets.assigned_to');
            } elseif ($ticketState !== null && $ticketState !== 'all') {
                $query->where('chat_tickets.status', $ticketState);
            }

            if ($role === 'agent' && !$allowAgentsViewAllChats) {
                $query->where('chat_tickets.assigned_to', auth()->user()->id);
            }
        }

        // Search filter
        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('contacts.first_name', 'like', "%$searchTerm%")
                    ->orWhere('contacts.last_name', 'like', "%$searchTerm%")
                    ->orWhereRaw("CONCAT(contacts.first_name, ' ', contacts.last_name) LIKE ?", ["%$searchTerm%"])
                    ->orWhere('contacts.phone', 'like', "%$searchTerm%")
                    ->orWhere('contacts.email', 'like', "%$searchTerm%");
            });
        }

        $query->orderBy('contacts.latest_chat_created_at', $sortDirection);

        $perPage = 10;
        $page = LengthAwarePaginator::resolveCurrentPage();

        if ($total === null) {
            $total = (clone $query)->count();
        }

        $items = $query->forPage($page, $perPage)->get();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
    }

    public function contactsWithChatsCount($organizationId, $searchTerm = null, $ticketingActive = false, $ticketState = null, $sortDirection = 'asc', $role = 'owner', $allowAgentsViewAllChats = true, $labelFilters = [], $assignedToMe = false, $assignedToFilter = null, $unreadOnly = false, $awaitingReply = false)
    {
        $needsTicketJoin = $ticketingActive && (
            $assignedToFilter !== null
            || ($assignedToMe && $role === 'agent')
            || $ticketState === 'unassigned'
            || ($ticketState !== null && $ticketState !== 'all')
            || ($role === 'agent' && !$allowAgentsViewAllChats)
        );

        $query = $this->newQuery()
            ->where('contacts.organization_id', $organizationId)
            ->whereNotNull('contacts.latest_chat_created_at')
            ->whereNull('contacts.deleted_at')
            ->select('contacts.id');

        // Apply unread messages filter
        if ($unreadOnly) {
            $query->whereHas('chats', function ($q) {
                $q->where('type', 'inbound')
                  ->where('is_read', 0)
                  ->whereNull('deleted_at');
            });
        }

        if ($awaitingReply) {
            $query->whereHas('lastChat', function ($q) {
                $q->where('type', 'inbound');
            });
        }

        if ($needsTicketJoin) {
            $query->leftJoin('chat_tickets', 'contacts.id', '=', 'chat_tickets.contact_id');

            // Conditional comparison with ticketState
            if ($assignedToFilter !== null) {
                $query->where('chat_tickets.assigned_to', $assignedToFilter);
            } elseif ($ticketState === 'unassigned') {
                $query->whereNull('chat_tickets.assigned_to');
            } elseif ($ticketState !== null && $ticketState !== 'all') {
                $query->where('chat_tickets.status', $ticketState);
            }

            // "Assigned to Me" filter
            if ($assignedToMe && $role === 'agent') {
                $query->where('chat_tickets.assigned_to', auth()->user()->id);
            }

            if ($role == 'agent' && $allowAgentsViewAllChats == false) {
                $query->where(function ($q) {
                    $q->where('chat_tickets.assigned_to', auth()->user()->id);
                });
            }
        }

        // Apply label filtering
        if (!empty($labelFilters)) {
            $query->whereHas('labels', function ($q) use ($labelFilters) {
                $q->whereIn('labels.id', $labelFilters);
            });
        }

        // Include the search term in the query if provided
        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('contacts.first_name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('contacts.last_name', 'like', '%' . $searchTerm . '%')

                    // Split the search term into parts and check for matches in both columns
                    ->orWhere(function ($subQuery) use ($searchTerm) {
                        $searchParts = explode(' ', $searchTerm);
                        if (count($searchParts) > 1) {
                            $subQuery->where('contacts.first_name', 'like', '%' . $searchParts[0] . '%')
                                ->where('contacts.last_name', 'like', '%' . $searchParts[1] . '%');
                        }
                    })

                    // Match phone or email
                    ->orWhere('contacts.phone', 'like', '%' . $searchTerm . '%')
                    ->orWhere('contacts.email', 'like', '%' . $searchTerm . '%');
            });
        }

        return $needsTicketJoin
            ? $query->distinct()->count('contacts.id')
            : $query->count();
    }

    public function getFirstNameAttribute()
    {
        $firstName = $this->attributes['first_name'];
        $firstName = $this->decodeUnicodeBytes($firstName);

        return $firstName;
    }

    public function getLastNameAttribute()
    {
        $lastName = $this->attributes['last_name'];
        $lastName = $this->decodeUnicodeBytes($lastName);

        return $lastName;
    }

    public function getFullNameAttribute()
    {
        $firstName = $this->attributes['first_name'];
        $lastName = $this->attributes['last_name'];

        // Convert byte sequences to Unicode characters
        $firstName = $this->decodeUnicodeBytes($firstName);
        $lastName = $this->decodeUnicodeBytes($lastName);

        // Return the full name combining first name and last name
        return $firstName . ' ' . $lastName;

        //return "{$this->first_name} {$this->last_name}";
    }

    public function getFormattedPhoneNumberAttribute($value)
    {
        // Use the phone() helper function to format the phone number to international format
        try {
            $formatted = phone($this->phone)->formatInternational();
        } catch (\Exception $e) {
            $formatted = $this->phone;
        }

        if ($this->shouldMaskPhoneForCurrentViewer()) {
            return $this->getMaskedPhoneForCurrentViewer();
        }

        return $formatted;
    }

    public function shouldMaskPhoneForCurrentViewer(): bool
    {
        $viewer = Auth::user() ?? Auth::guard('sanctum')->user();
        if (! $viewer) {
            return false;
        }

        $organizationId = (int) ($this->organization_id ?? 0);
        if ($organizationId <= 0) {
            return false;
        }

        if (! array_key_exists($organizationId, self::$phoneMaskingEnabledCache)) {
            $metadata = Organization::where('id', $organizationId)->value('metadata');
            $decoded = is_string($metadata) ? json_decode($metadata, true) : [];
            self::$phoneMaskingEnabledCache[$organizationId] = (bool) ($decoded['tickets']['mask_agent_phone_numbers'] ?? false);
        }

        if (! self::$phoneMaskingEnabledCache[$organizationId]) {
            return false;
        }

        $roleKey = $organizationId . ':' . $viewer->id;
        if (! array_key_exists($roleKey, self::$viewerRoleCache)) {
            self::$viewerRoleCache[$roleKey] = Team::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $viewer->id)
                ->whereNull('deleted_at')
                ->value('role');
        }

        return self::$viewerRoleCache[$roleKey] === 'agent';
    }

    public function getMaskedPhoneForCurrentViewer(): string
    {
        return $this->maskPhoneKeepingFirst3Last2((string) ($this->phone ?? ''));
    }

    private function maskPhoneKeepingFirst3Last2(string $phone): string
    {
        $hasPlus = str_starts_with(trim($phone), '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return $phone;
        }

        $length = strlen($digits);
        if ($length <= 5) {
            $masked = substr($digits, 0, 1) . str_repeat('*', max($length - 1, 1));
            return $hasPlus ? '+' . $masked : $masked;
        }

        $masked = substr($digits, 0, 3)
            . str_repeat('*', $length - 5)
            . substr($digits, -2);

        return $hasPlus ? '+' . $masked : $masked;
    }

    protected function decodeUnicodeBytes($value)
    {
        return preg_replace_callback('/\\\\x([0-9A-F]{2})/i', function ($matches) {
            return chr(hexdec($matches[1]));
        }, $value);
    }

    public function labels()
    {
        return $this->belongsToMany(Label::class, 'contact_label')->withTimestamps();
    }

    public function ticket()
    {
        return $this->hasOne(ChatTicket::class, 'contact_id');
    }

    public function latestAssignedUser()
    {
        return $this->hasOneThrough(
            User::class,
            ChatTicket::class,
            'contact_id',
            'id',
            'id',
            'assigned_to'
        )->whereNull('users.deleted_at')->latest('chat_tickets.id');
    }
}
