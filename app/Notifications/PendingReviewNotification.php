<?php

namespace App\Notifications;

use App\Models\Review;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "A review awaits moderation" — to every admin.
 */
class PendingReviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $bookTitle;

    public string $reviewer;

    public int $rating;

    public string $excerpt;

    /**
     * A snapshot of the review instead of the model: the author may edit or delete
     * it before the queued notification is sent.
     */
    public function __construct(Review $review)
    {
        $this->bookTitle = $review->book->title;
        $this->reviewer = $review->user->name;
        $this->rating = $review->rating->value;
        $this->excerpt = Str::limit($review->body, 200);
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        // broadcast (stage 10): pushed to the private App.Models.User.{id} channel;
        // the bell in the header bumps its counter without a page reload.
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.pending_review.subject', ['title' => $this->bookTitle]))
            ->markdown('mail.pending-review', [
                'title' => $this->bookTitle,
                'reviewer' => $this->reviewer,
                'rating' => $this->rating,
                'excerpt' => $this->excerpt,
                'url' => route('admin.reviews.index'),
            ]);
    }

    /**
     * Payload of both the database row and the broadcast message (no toDatabase()
     * or toBroadcast() here, so Laravel uses toArray() for each). Echo receives it
     * as {id, type, ...this array}.
     *
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'kind' => 'pending_review',
            'params' => ['title' => $this->bookTitle, 'reviewer' => $this->reviewer],
            'url' => route('admin.reviews.index'),
        ];
    }
}
