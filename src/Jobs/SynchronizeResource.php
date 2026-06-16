<?php

namespace Webkul\Google\Jobs;

abstract class SynchronizeResource
{
    /**
     * The synchronizable instance.
     */
    protected $synchronizable;

    /**
     * The synchronization instance.
     */
    protected $synchronization;

    /**
     * Whether the stale sync token has already been reset for this run, to
     * guard against re-entering the full re-sync more than once.
     */
    protected bool $hasResetSyncToken = false;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($synchronizable)
    {
        $this->synchronizable = $synchronizable;

        $this->synchronization = $synchronizable->synchronization;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $pageToken = null;

        $syncToken = $this->synchronization->token;

        $service = $this->synchronizable->getGoogleService('Calendar');

        do {
            $tokens = compact('pageToken', 'syncToken');

            try {
                $list = $this->getGoogleRequest($service, $tokens);
            } catch (\Google_Service_Exception $e) {
                if ($this->isInvalidSyncToken($e) && ! $this->hasResetSyncToken) {
                    $this->hasResetSyncToken = true;

                    $this->synchronization->update(['token' => null]);
                    $this->dropAllSyncedItems();

                    return $this->handle();
                }

                throw $e;
            }

            foreach ($list->getItems() as $item) {
                $this->syncItem($item);
            }

            $pageToken = $list->getNextPageToken();
        } while ($pageToken);

        $this->synchronization->update([
            'token'                => $list->getNextSyncToken(),
            'last_synchronized_at' => now(),
        ]);
    }

    /**
     * Determine whether the exception means the stored sync token is no longer
     * usable, so the resource must be fully re-synced from scratch.
     *
     * Google returns 410 (Gone) when a sync token has simply expired, but a
     * malformed or otherwise invalid token comes back as 400 with an "invalid"
     * reason ("Invalid sync token value."). Both are recovered the same way:
     * drop the token and run a full synchronization.
     */
    protected function isInvalidSyncToken(\Google_Service_Exception $e): bool
    {
        if ($e->getCode() === 410) {
            return true;
        }

        if ($e->getCode() === 400) {
            foreach ((array) $e->getErrors() as $error) {
                if (
                    ($error['reason'] ?? null) === 'invalid'
                    && str_contains(strtolower($error['message'] ?? ''), 'sync token')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the Google request.
     *
     * @param  mixed  $service
     * @param  mixed  $options
     * @return mixed
     */
    abstract public function getGoogleRequest($service, $options);

    /**
     * Sync the item.
     *
     * @param  mixed  $item
     * @return mixed
     */
    abstract public function syncItem($item);

    /**
     * Drop all synced items.
     *
     * @return mixed
     */
    abstract public function dropAllSyncedItems();
}
