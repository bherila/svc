<?php

namespace App\Support;

/**
 * One repository, spelled one way.
 *
 * A checkout can name its origin in at least five shapes that all mean the same
 * remote - `https://github.com/owner/name.git`, `git@github.com:owner/name`,
 * `ssh://git@github.com:22/owner/name/`, and the same again without the suffix
 * or with a trailing slash. A stored mapping is only useful if every one of
 * them resolves to the single value that was stored, so both sides of the
 * comparison run through here: the Manage form on the way in, and the
 * `log-time` skill's own normalization on the way out.
 *
 * The canonical form is `host/owner/name`, lowercase, no scheme, no user, no
 * port, no `.git`, no trailing slash.
 *
 * ## Why the whole reference is lowercased, not only the host
 *
 * Hostnames are case-insensitive by specification; repository paths are not,
 * and on a case-sensitive host `owner/Name` and `owner/name` could in principle
 * be two repositories. Every host this is actually used against - GitHub,
 * GitLab, Bitbucket - resolves paths case-insensitively and will hand out only
 * one of the pair, so the realistic failure is the opposite one: an operator
 * types `github.com/Bherila/svc` into the form, the checkout reports
 * `github.com/bherila/svc`, and the mapping silently never matches. That is
 * exactly the "several spellings, one value" problem this class exists for, so
 * case is folded across the whole reference.
 *
 * The cost is bounded and visible: on a hypothetically case-sensitive host, two
 * projects would both match one remote, which is already an ordinary situation
 * here - a repository may legitimately bill to two projects - and is resolved by
 * asking rather than by guessing. Collapsing to an ambiguous prompt is a much
 * cheaper wrong answer than never matching at all.
 *
 * ## Why more than three segments are allowed
 *
 * `host/owner/name` is the common shape, but GitLab subgroups are genuinely
 * deeper (`gitlab.com/group/subgroup/name`). Three segments is a floor rather
 * than an exact count.
 */
final class RepositoryReference
{
    /**
     * A hostname: dot-separated labels, or a bare name like `localhost`.
     *
     * Deliberately not anchored to a public suffix. Self-hosted Git lives on
     * intranet names and this is a mapping key, not a security boundary.
     */
    private const HOST = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';

    /**
     * One path segment: anything but a slash, whitespace or the empty string.
     */
    private const SEGMENT = '/^[^\s\/]+$/';

    /**
     * Reduce any spelling of a remote to the canonical one.
     *
     * Returns `null` for input that is absent, blank, or not recognisable as a
     * repository reference. A caller that needs to tell "the operator cleared
     * this field" from "the operator typed something unusable" must validate
     * first - see {@see self::isNormalizable()} - because both arrive here as
     * `null` by design: this method never throws, so it is safe to run over
     * stored values during a read.
     */
    public static function normalize(?string $raw): ?string
    {
        // No early return for null or blank. Both converge on the segment count
        // below - `explode('/', '')` is one empty segment, which is fewer than
        // three - so a guard here would only be a second way to say the same
        // thing, and one the tests could not tell apart from its own absence.
        //
        // Backslashes become slashes before anything looks at the shape.
        // Windows spells paths with them, and folding the two separators here
        // means every check below reads one form instead of two.
        $value = str_replace('\\', '/', trim($raw ?? ''));

        // `file://` never names a remote host, whatever authority it carries.
        // Verified against the Git CLI: `git ls-remote file://localhost/tmp/x`
        // runs `git-upload-pack '/tmp/x'` with no ssh at all, discarding the
        // authority - so storing `localhost/tmp/x` would be a key for a path on
        // one machine, and one that could collide with a real host of that name.
        if (str_starts_with(strtolower($value), 'file://')) {
            return null;
        }

        // Strip the scheme by attempting it, rather than testing first and then
        // stripping: one pattern, applied once, and whether it matched *is* the
        // answer to "was there a scheme". That question matters because the two
        // forms have genuinely different grammars, and the rest of this method
        // is the two of them kept apart.
        $withoutScheme = self::replace('#^[a-z][a-z0-9+.-]*://#i', '', $value);
        $hadScheme = $withoutScheme !== $value;
        $value = $withoutScheme;

        if ($hadScheme) {
            // A URL authority: `[user[:password]@]host[:port]`, ending at the
            // first `/`. User information and the port are both outside the
            // identity.
            $value = self::replace('#^[^/@]*@#', '', $value);

            // The port only when it really is one. Git's grammar puts it
            // between the host and the path separator, so digits followed by
            // anything else is a malformed authority: without the lookahead,
            // `host:22evil/owner/name` loses `:22` and silently becomes the
            // altogether different host `hostevil`.
            $value = self::replace('#^([^/:]+):\d+(?=/|$)#', '$1', $value);

            // Only a URL has a query or a fragment. In the SCP form below these
            // are literal path characters, which is why this lives in this
            // branch and not after the two.
            $value = self::replace('/[?#].*$/', '', $value);
        } else {
            // SCP-style, `[user@]host:path`. The **first** colon is the whole
            // boundary, and everything after it is passed to the server
            // verbatim - confirmed with `GIT_TRACE=1 git ls-remote`, which
            // invokes `git-upload-pack` on exactly that string.
            //
            // So the split has to happen before anything else is stripped. An
            // earlier version ran a URL-style `user@` strip across the whole
            // value first, which turned `host:owner@tenant/group/repo` into the
            // unrelated key `tenant/group/repo`: the `@` was in the path, where
            // it means nothing, not in the authority.
            $colon = strpos($value, ':');

            if ($colon !== false) {
                $authority = substr($value, 0, $colon);
                $path = substr($value, $colon + 1);

                // A single-letter authority is a Windows drive, not a host.
                // `C:/srv/git/repo` and `C:srv/git` are paths on one machine.
                if (strlen($authority) === 1) {
                    return null;
                }

                // An absolute path is a *different* repository: `host:owner/name`
                // is relative to the login home, `host:/owner/name` to the root.
                // The canonical form has nowhere to record which, so rather than
                // fold two remotes onto one key it is refused and the operator is
                // asked - the same path taken when nothing matches at all.
                if (str_starts_with($path, '/')) {
                    return null;
                }

                // User information, and only within the authority.
                $value = self::replace('#^[^/@]*@#', '', $authority).'/'.$path;
            }
        }

        $value = strtolower($value);

        // `.git`, then any trailing slashes it was hiding behind, in that order
        // so `.../name.git/` reduces the same as `.../name.git` and `.../name/`.
        $value = self::replace('/\.git$/', '', rtrim($value, '/'));
        $value = rtrim($value, '/');

        // Collapse repeated separators so `host//owner/name` is not three
        // segments plus an empty one. A *leading* slash is deliberately left
        // alone: it means there was no host - `file:///srv/git/repo` reduces to
        // `/srv/git/repo` - and the empty first segment is what makes the host
        // check below reject it. Trimming it would promote `srv` to a hostname
        // and mint a mapping key for a path that names no server at all.
        $value = self::replace('#/{2,}#', '/', $value);

        $segments = explode('/', $value);

        if (count($segments) < 3) {
            return null;
        }

        $host = array_shift($segments);

        if (preg_match(self::HOST, $host) !== 1) {
            return null;
        }

        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                return null;
            }
        }

        return $host.'/'.implode('/', $segments);
    }

    /**
     * `preg_replace` with a failure that leaves the subject alone.
     *
     * It returns `string|null`, null only on a PREG engine error - a backtrack
     * limit, or invalid UTF-8 under `/u`. None of these patterns can reach that
     * on any input, so the coalesce is unreachable rather than untested, and
     * leaving the subject unchanged is the conservative branch regardless: a
     * value that fails to normalize falls out at the shape checks below and
     * becomes `null`, which is "nobody has said" and matches nothing.
     *
     * @infection-ignore-all The null branch is unreachable for these patterns; it exists so the return type is `string` without a cast at four call sites.
     */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }

    /**
     * Whether this input has a canonical form.
     *
     * Blank input is *not* normalizable - clearing the field is a separate act
     * from typing something unusable, and only the caller knows which one it is
     * looking at. Validation rules pair this with `nullable`.
     */
    public static function isNormalizable(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }
}
