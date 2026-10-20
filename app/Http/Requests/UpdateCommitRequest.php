<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Commit;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateCommitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user->id === $this->commit()->user_id;
    }

    public function prepareForValidation(): void
    {
        CreateCommitRequest::normalizeBody($this);
    }

    /**
     * Images still make text optional, as they did when the commit was made.
     *
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'body' => CreateCommitRequest::bodyRules($this->commit()->images()->exists()),
            'original_body' => ['present', 'nullable', 'string'],
        ];
    }

    /**
     * Only the text of a fresh commit nobody has answered yet can change. Polls stay as they are, so a question people
     * already voted on keeps meaning the same.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $commit = $this->commit();

                $error = match (true) {
                    now()->greaterThanOrEqualTo($commit->created_at->copy()->addMinutes(Commit::AMENDABLE_MINUTES)) => 'The 15-minute amendment window has closed. Your changes were not saved.',
                    $commit->replies()->withTrashed()->exists(), $commit->quotes()->withTrashed()->exists() => 'This commit has received a reply or quote and can no longer be amended. Your changes were not saved.',
                    $commit->poll()->exists() => 'Commits with a poll cannot be amended.',
                    $commit->revisions()->count() >= Commit::MAX_AMENDMENTS => 'This commit has been amended '.Commit::MAX_AMENDMENTS.' times already.',
                    $this->string('original_body')->value() !== $commit->body => 'This commit has changed since you opened the amendment form. Your changes were not saved.',
                    default => null,
                };

                if ($error !== null) {
                    $validator->errors()->add('body', $error);
                }
            },
        ];
    }

    public function commit(): Commit
    {
        $commit = $this->route('commit');
        assert($commit instanceof Commit);

        return $commit;
    }
}
