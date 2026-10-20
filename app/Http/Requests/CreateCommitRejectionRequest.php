<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Commit;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CreateCommitRejectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user->isAdmin();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:280'],
            'expected_body' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * An author may amend a commit while it waits, and a moderator only ever decides on the text they read.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $commit = $this->route('commit');
                assert($commit instanceof Commit);

                if ($this->has('expected_body') && $this->string('expected_body')->value() !== $commit->body) {
                    $validator->errors()->add('body', 'Not rejected: the author amended this commit. Review the new text.');
                }
            },
        ];
    }
}
