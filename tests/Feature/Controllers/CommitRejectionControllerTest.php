<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;

beforeEach(function (): void {
    config(['app.admins' => ['admin']]);
});

it('may reject a commit', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $commit = Commit::factory()->pending()->create();

    $response = $this->actingAs($admin)
        ->fromRoute('moderation.show')
        ->post(route('commit-rejection.store', $commit));

    $response->assertRedirectToRoute('moderation.show');

    expect($commit->refresh()->status)->toBe(CommitStatus::Rejected);
});

it('may not reject a commit as a non admin', function (): void {
    $commit = Commit::factory()->pending()->create();

    $response = $this->actingAs($commit->user)
        ->post(route('commit-rejection.store', $commit));

    $response->assertForbidden();

    expect($commit->refresh()->status)->toBe(CommitStatus::Pending);
});

it('may not reject text the author amended after it was read', function (): void {
    $admin = User::factory()->create(['username' => 'admin']);
    $commit = Commit::factory()->pending()->create(['body' => 'Amended']);

    $this->actingAs($admin)
        ->post(route('commit-rejection.store', $commit), ['expected_body' => 'Original'])
        ->assertSessionHasErrors(['body' => 'Not rejected: the author amended this commit. Review the new text.']);

    expect($commit->refresh()->status)->toBe(CommitStatus::Pending);
});
