<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Shipfastlabs\Grant\Tests\Fixtures\Post;
use Shipfastlabs\Grant\Tests\Fixtures\PostPolicy;
use Shipfastlabs\Grant\Tests\Fixtures\Role;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);
});

it('requires the attributed permission and still runs the policy', function (): void {
    $owner = $this->createUser('Owner');
    $other = $this->createUser('Other');
    $post = new Post($owner->getId());

    $owner->grant(Role::Viewer);
    expect(Gate::forUser($owner)->allows('update', $post))->toBeFalse();

    $owner->grant(Role::Editor);
    expect(Gate::forUser($owner)->allows('update', $post))->toBeTrue();

    $other->grant(Role::Editor);
    expect(Gate::forUser($other)->allows('update', $post))->toBeFalse();
});

it('does not recurse when the attribute names the policy ability itself', function (): void {
    $user = $this->createUser('Viewer');
    $user->grant(Role::Viewer);

    expect(Gate::forUser($user)->allows('view-reports', new Post))->toBeTrue();
});

it('denies with the attributed permission’s message', function (): void {
    $user = $this->createUser('Viewer');
    $user->grant(Role::Viewer);

    expect(Gate::forUser($user)->inspect('update', new Post)->message())->toBe('Editor access is required.');
});
