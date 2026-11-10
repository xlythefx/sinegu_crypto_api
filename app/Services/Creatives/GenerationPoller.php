<?php

namespace App\Services\Creatives;

use App\Models\CreativeGeneration;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Keeps each generation's row in step with Higgsfield, which pushes nothing:
 * the generations list refreshes a few stale rows before it answers, and
 * `creatives:poll` (every minute) catches the rest.
 *
 * Money rules applied here: `completed` keeps its reservation (that is the
 * charge); `failed` / `nsfw` / `canceled` give it back (Higgsfield does not
 * charge them). Anything we cannot confirm keeps it — the cap errs on the
 * side of having spent.
 */
final class GenerationPoller
{
    /** The record of a submit nobody confirmed (also SUBMIT_UNCONFIRMED's message). */
    public const UNCONFIRMED = 'Higgsfield did not confirm this request — check its usage page';

    /** A row still `submitting` this long after it was made lost its submit. */
    public const SUBMITTING_GIVE_UP_MINUTES = 10;

    public function __construct(private HiggsfieldGateway $gateway) {}

    /**
     * Ask Higgsfield about up to $limit running rows not checked in the last
     * $staleSeconds, oldest check first. Nothing pending = no request at all.
     *
     * The batch STOPS at the first answer that would repeat for every row —
     * no answer, a refused key, a rate limit — so a Higgsfield outage costs
     * the generations page one timeout, not ten, and the log one line.
     *
     * @return int rows asked about
     */
    public function refreshPending(int $limit, int $staleSeconds): int
    {
        if (! $this->gateway->configured()) {
            return 0;
        }

        $rows = CreativeGeneration::query()
            ->whereIn('status', [CreativeGeneration::QUEUED, CreativeGeneration::IN_PROGRESS])
            ->whereNotNull('request_id')
            ->where(fn ($q) => $q->whereNull('last_checked_at')
                ->orWhere('last_checked_at', '<', now()->subSeconds($staleSeconds)->toDateTimeString()))
            ->orderByRaw('last_checked_at IS NOT NULL')
            ->orderBy('last_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $asked = 0;
        foreach ($rows as $row) {
            $asked++;
            try {
                $reply = $this->check($row);
            } catch (Throwable $e) {
                // One odd answer must not stop the rest of the batch.
                report($e);

                continue;
            }
            if ($reply !== null && ($reply->isNoAnswer() || in_array($reply->status, [401, 403, 429], true))) {
                break;
            }
        }

        return $asked;
    }

    /** One status call, applied to the row. */
    public function refresh(CreativeGeneration $generation): CreativeGeneration
    {
        $this->check($generation);

        return $generation;
    }

    /** @return HiggsfieldReply|null null when the row is not running (nothing asked) */
    private function check(CreativeGeneration $generation): ?HiggsfieldReply
    {
        if (! in_array($generation->status, [CreativeGeneration::QUEUED, CreativeGeneration::IN_PROGRESS], true)
            || $generation->request_id === null) {
            return null;
        }

        $reply = $this->gateway->status($generation->request_id);
        $generation->last_checked_at = now();

        if ($reply->isRefused() && $reply->status === 404) {
            // It vanished at Higgsfield. Whether it cost anything is unknown,
            // so the reservation stays.
            $generation->status = CreativeGeneration::FAILED;
            $generation->error = 'Higgsfield no longer knows this request — check its usage page';
        } elseif ($reply->isOk()) {
            $this->apply($generation, $reply->data);
        }

        $generation->save();

        return $reply;
    }

    /** Completed rows past their expiry: the files are gone at Higgsfield. */
    public function expireFinished(): int
    {
        return CreativeGeneration::query()
            ->where('status', CreativeGeneration::COMPLETED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->toDateTimeString())
            ->update(['status' => CreativeGeneration::EXPIRED, 'outputs' => null, 'updated_at' => now()]);
    }

    /**
     * A row still `submitting` long after it was made: the process died
     * between reserving and hearing back. Recorded as unconfirmed, charge
     * kept — the same verdict as a submit Higgsfield never answered.
     */
    public function failStaleSubmitting(): int
    {
        return CreativeGeneration::query()
            ->where('status', CreativeGeneration::SUBMITTING)
            ->where('created_at', '<', now()->subMinutes(self::SUBMITTING_GIVE_UP_MINUTES)->toDateTimeString())
            ->update(['status' => CreativeGeneration::FAILED, 'error' => self::UNCONFIRMED, 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $data  Higgsfield's status body */
    private function apply(CreativeGeneration $generation, array $data): void
    {
        $status = is_string($data['status'] ?? null) ? $data['status'] : '';

        switch ($status) {
            case CreativeGeneration::QUEUED:
            case CreativeGeneration::IN_PROGRESS:
                $generation->status = $status;
                break;

            case CreativeGeneration::COMPLETED:
                $generation->status = CreativeGeneration::COMPLETED;
                $generation->outputs = self::outputs($data);
                $generation->completed_at ??= now();
                $generation->expires_at = self::expiry($data['expires_at'] ?? null);
                $generation->error = null;
                break;

            case CreativeGeneration::FAILED:
            case CreativeGeneration::NSFW:
            case CreativeGeneration::CANCELED:
                $generation->status = $status;
                $generation->charged_usd = 0;
                $generation->error = self::errorText($data['error'] ?? null) ?? match ($status) {
                    CreativeGeneration::NSFW => 'Higgsfield\'s moderation blocked this result. Nothing was charged.',
                    CreativeGeneration::FAILED => 'Higgsfield could not make this. Nothing was charged.',
                    default => null,
                };
                break;

            default:
                // A status we do not know yet: leave the row as it is.
                break;
        }
    }

    /**
     * The files, from Higgsfield's own answer — the ONLY URLs the download
     * route will ever fetch. https only.
     *
     * @return list<array{type: string, url: string}>
     */
    public static function outputs(array $data): array
    {
        $out = [];
        $video = $data['video'] ?? null;
        if (is_array($video) && self::usableUrl($video['url'] ?? null)) {
            $out[] = ['type' => 'video', 'url' => $video['url']];
        }
        foreach ((array) ($data['images'] ?? []) as $image) {
            if (is_array($image) && self::usableUrl($image['url'] ?? null)) {
                $out[] = ['type' => 'image', 'url' => $image['url']];
            }
        }

        return $out;
    }

    private static function usableUrl(mixed $url): bool
    {
        return is_string($url) && strlen($url) <= 2048 && str_starts_with($url, 'https://')
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** Higgsfield's expiry, or its default retention when it sent none. */
    private static function expiry(mixed $value): Carbon
    {
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->utc();
            } catch (Throwable) {
                // fall through
            }
        }

        return now()->addDays(CreativeModels::RETENTION_DAYS);
    }

    private static function errorText(mixed $error): ?string
    {
        if (is_array($error)) {
            $error = $error['message'] ?? $error['detail'] ?? $error['error'] ?? null;
        }
        if (! is_string($error) || trim($error) === '') {
            return null;
        }

        return mb_substr(trim($error), 0, 480);
    }
}
