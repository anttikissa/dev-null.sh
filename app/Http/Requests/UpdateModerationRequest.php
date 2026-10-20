<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Commit;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateModerationRequest extends FormRequest
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
            'commits' => ['required', 'array', 'max:100'],
            'commits.*' => ['required', 'uuid', 'distinct'],
            'bodies' => ['sometimes', 'array', 'max:100'],
            'bodies.*' => ['nullable', 'string'],
        ];
    }

    /**
     * Nothing is approved when an author amended one of the selected commits after the moderator read it.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<string, string|null> $bodies */
                $bodies = $this->array('bodies');

                $amended = Commit::query()->whereKey(array_keys($bodies))->get(['id', 'body'])
                    ->contains(fn (Commit $commit): bool => $commit->body !== (string) $bodies[$commit->id]);

                if ($amended) {
                    $validator->errors()->add('body', 'Nothing approved: the author amended a selected commit. Review the new text.');
                }
            },
        ];
    }
}
