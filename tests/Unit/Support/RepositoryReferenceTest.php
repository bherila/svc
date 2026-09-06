<?php

namespace Tests\Unit\Support;

use App\Support\RepositoryReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The point of the normalizer is that every spelling of one remote collapses to
 * the same key, so most of this file is one repository written many ways with
 * one expected answer. A case that only asserted "https works" would pass with
 * the whole SCP branch deleted.
 */
final class RepositoryReferenceTest extends TestCase
{
    #[DataProvider('spellingsOfOneRepository')]
    public function test_every_spelling_of_one_remote_normalizes_to_one_key(string $raw): void
    {
        $this->assertSame('github.com/bherila/svc', RepositoryReference::normalize($raw));
    }

    /** @return iterable<string, array{string}> */
    public static function spellingsOfOneRepository(): iterable
    {
        yield 'already canonical' => ['github.com/bherila/svc'];
        yield 'https' => ['https://github.com/bherila/svc'];
        yield 'https with suffix' => ['https://github.com/bherila/svc.git'];
        yield 'https with trailing slash' => ['https://github.com/bherila/svc/'];
        yield 'https with suffix and slash' => ['https://github.com/bherila/svc.git/'];
        yield 'http' => ['http://github.com/bherila/svc'];
        yield 'scp style' => ['git@github.com:bherila/svc.git'];
        yield 'scp style without suffix' => ['git@github.com:bherila/svc'];
        yield 'ssh url' => ['ssh://git@github.com/bherila/svc.git'];
        yield 'ssh url with port' => ['ssh://git@github.com:22/bherila/svc.git'];
        yield 'git protocol' => ['git://github.com/bherila/svc.git'];
        yield 'mixed case host' => ['https://GitHub.com/bherila/svc'];
        // The scheme match is case-insensitive. Without that, an uppercase
        // scheme is not recognised as one, the SCP branch reads `HTTPS:` as a
        // host separator, and the whole reference comes out as
        // `https/github.com/bherila/svc`.
        yield 'uppercase scheme' => ['HTTPS://GitHub.com/Bherila/SVC'];
        yield 'mixed case path' => ['https://github.com/Bherila/SVC'];
        yield 'surrounding whitespace' => ['  https://github.com/bherila/svc.git  '];
        yield 'query string' => ['https://github.com/bherila/svc.git?ref=main'];
        // A drive letter inside a URL query is part of the query, not a path
        // on this machine - the refusal below applies to the SCP form only.
        yield 'a drive letter inside a query string' => ['https://github.com/bherila/svc.git?path=c:/tmp'];
        yield 'fragment' => ['https://github.com/bherila/svc#readme'];
        yield 'doubled separators' => ['https://github.com//bherila//svc'];
        // The second `rtrim` exists for this one: stripping `.git` uncovers the
        // slash that was in front of it, and without the second pass the value
        // keeps a trailing separator and gains an empty final segment.
        yield 'suffix behind its own slash' => ['https://github.com/bherila/svc/.git'];
    }

    /**
     * The SCP form has no scheme, and Git reads its first colon as the start of
     * the path rather than as a port. A normalizer that dropped `:\d+`
     * unconditionally would turn `host:1234/owner/name` into `host/owner/name`
     * and silently map two different repositories onto one key.
     */
    public function test_a_numeric_scp_path_is_a_path_and_not_a_port(): void
    {
        $this->assertSame(
            'git.example.test/1234/owner/name',
            RepositoryReference::normalize('git@git.example.test:1234/owner/name.git'),
        );
    }

    /** A port in a real URL is not part of the identity, unlike the case above. */
    public function test_a_port_in_a_url_is_dropped(): void
    {
        $this->assertSame(
            'git.example.test/owner/name',
            RepositoryReference::normalize('ssh://git@git.example.test:2222/owner/name.git'),
        );
    }

    /**
     * Credentials are not part of the identity.
     *
     * A remote pasted from a machine that embeds a token would otherwise store
     * the token in a client record and match nothing, which is two problems.
     * On a reserved domain deliberately - the disclosure scan reads a
     * `user@host` on a real domain as an address, and it is right to.
     */
    public function test_credentials_are_not_part_of_the_identity(): void
    {
        $this->assertSame(
            'example.com/owner/name',
            RepositoryReference::normalize('https://someone@example.com/owner/name.git'),
        );
        $this->assertSame(
            'example.com/owner/name',
            RepositoryReference::normalize('https://someone:a-secret@example.com/owner/name.git'),
        );
    }

    /**
     * `?` and `#` are literal in an SCP path, and only there.
     *
     * `GIT_TRACE=1 git ls-remote git@example.test:owner/repo#archive.git` shows
     * Git invoking `git-upload-pack 'owner/repo#archive.git'` verbatim, so
     * stripping from the `#` would key a different repository on that host -
     * and could bill time to the wrong project. A URL fragment is genuinely not
     * part of the identity, which is why the strip lives in that branch only.
     */
    public function test_query_and_fragment_characters_are_literal_in_an_scp_path(): void
    {
        $this->assertSame(
            'example.test/owner/repo#archive',
            RepositoryReference::normalize('git@example.test:owner/repo#archive.git'),
        );
        $this->assertSame(
            'example.test/owner/repo?live',
            RepositoryReference::normalize('git@example.test:owner/repo?live.git'),
        );
    }

    /**
     * An `@` in an SCP *path* is not user information.
     *
     * `git ls-remote example.test:owner@tenant/group/repo.git` invokes ssh on
     * `example.test` with the path `owner@tenant/group/repo.git`. Stripping an
     * optional `user@` before finding the host/path boundary consumed
     * `example.test:owner@` and produced the unrelated key
     * `tenant/group/repo` - a mapping pointing at someone else's project.
     */
    public function test_an_at_sign_in_an_scp_path_is_not_user_information(): void
    {
        $this->assertSame(
            'example.test/owner@tenant/group/repo',
            RepositoryReference::normalize('example.test:owner@tenant/group/repo.git'),
        );
        $this->assertSame(
            'example.test/owner@tenant/group/repo',
            RepositoryReference::normalize('git@example.test:owner@tenant/group/repo.git'),
        );
    }

    /** GitLab subgroups are deeper than three segments and are still one repository. */
    public function test_subgroups_are_preserved(): void
    {
        $this->assertSame(
            'gitlab.com/group/subgroup/name',
            RepositoryReference::normalize('https://gitlab.com/group/subgroup/name.git'),
        );
    }

    /** Only the trailing `.git` goes; a repository may legitimately be named for one. */
    public function test_only_a_trailing_suffix_is_stripped(): void
    {
        $this->assertSame(
            'github.com/bherila/svc.github.io',
            RepositoryReference::normalize('https://github.com/bherila/svc.github.io'),
        );
    }

    #[DataProvider('unusableInput')]
    public function test_input_without_a_canonical_form_is_rejected(?string $raw): void
    {
        $this->assertNull(RepositoryReference::normalize($raw));
        $this->assertFalse(RepositoryReference::isNormalizable($raw));
    }

    /** @return iterable<string, array{?string}> */
    public static function unusableInput(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'host alone' => ['github.com'];
        yield 'host and owner only' => ['github.com/bherila'];
        yield 'a bare name' => ['svc'];
        yield 'a sentence' => ['the svc repo on github'];
        yield 'an underscore host' => ['git_hub.com/owner/name'];
        yield 'an empty host' => ['///owner/name'];
        yield 'a path with a space' => ['github.com/bherila/my svc'];
        // A local remote names no server, whatever authority it carries.
        // `git ls-remote file://localhost/tmp/x` runs `git-upload-pack '/tmp/x'`
        // with no ssh at all - Git discards the authority, so storing
        // `localhost/tmp/x` would key a path on one machine, and could collide
        // with a real host of that name.
        yield 'a local file remote' => ['file:///srv/git/repo.git'];
        yield 'a file remote with an authority' => ['file://localhost/tmp/repo.git'];
        yield 'a file remote with a host-shaped authority' => ['FILE://example.test/tmp/repo.git'];
        yield 'a bare absolute path' => ['/srv/git/repo'];
        // A drive letter is not a hostname. Without the guard the SCP rewrite
        // reads the drive colon as the host separator and mints `c/srv/git/repo`.
        yield 'a windows drive path' => ['C:/srv/git/repo.git'];
        yield 'a windows drive path with backslashes' => ['C:\\srv\\git\\repo'];
        // Drive-relative, with no slash after the colon. Git itself cannot tell
        // this from an SCP remote on a one-letter host; neither can this, so it
        // refuses rather than minting `c/srv/git`.
        yield 'a drive-relative windows path' => ['C:srv/git'];
        // `host:owner/name` is relative to the login home and `host:/owner/name`
        // to the root - two repositories the canonical form cannot tell apart,
        // so it refuses rather than folding them onto one key.
        yield 'an absolute scp path' => ['git@git.example.test:/owner/name.git'];
        // Digits followed by anything but a separator are not a port. Stripping
        // them anyway would glue the remainder to the host and accept the
        // altogether different `hostevil`.
        yield 'a malformed port' => ['ssh://git.example.test:22evil/owner/name.git'];
    }

    /**
     * Normalizing an already-normalized value must not move it, or a stored
     * mapping would drift every time it was written back.
     */
    #[DataProvider('spellingsOfOneRepository')]
    public function test_normalization_is_idempotent(string $raw): void
    {
        $once = RepositoryReference::normalize($raw);

        $this->assertNotNull($once);
        $this->assertSame($once, RepositoryReference::normalize($once));
    }
}
