<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Commit;
use App\Services\OpenAI;

final readonly class UpdateCommitEmbedding
{
    public function __construct(private OpenAI $openAI)
    {
        //
    }

    /**
     * The body is embedded when the commit is created, amended, or backfilled. An embedding of text that was amended
     * while it was being computed is dropped. Failures, such as timeouts or rate limits, are thrown, so the caller can
     * try again later.
     */
    public function handle(Commit $commit): void
    {
        $embedding = $this->openAI->embed($commit->body);

        if ($commit->fresh()?->body === $commit->body) {
            $commit->update(['embedding' => $embedding]);
        }
    }
}
