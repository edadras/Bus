<?php

namespace App\Domain\Support\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ComplaintAttachment extends Model
{
    protected $fillable = [
        'complaint_id', 'complaint_message_id', 'uploaded_by',
        'file_path', 'original_name', 'mime_type', 'size_bytes',
    ];

    protected $hidden = ['file_path'];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ComplaintMessage::class, 'complaint_message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Attachments may contain personal detail, so they are served through a
     * short lived signed URL rather than a public disk path.
     */
    public function temporaryUrl(int $minutes = 15): string
    {
        return Storage::disk('local')->temporaryUrl($this->file_path, now()->addMinutes($minutes));
    }
}
