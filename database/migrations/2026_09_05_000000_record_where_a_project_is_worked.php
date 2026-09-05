<?php

use App\Support\RepositoryReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which repository a project's work happens in.
 *
 * Work is done in a product repository and the time for it belongs to a
 * `client_projects` row here, and until now nothing in the schema recorded that
 * relationship. Every agent or script that wanted to log time from another
 * checkout had to carry its own copy of the mapping, and that copy could not
 * live in this repository - it is public, and a table of `owner/repo -> client
 * project` is client data. So the mapping was either re-derived by asking the
 * operator every time or kept in a personal file on one laptop, neither of
 * which survives a second machine or the operator. The mapping is workspace
 * data; this puts it in the workspace. See #243.
 *
 * ## What the null means
 *
 * `null` is "nobody has said", and it is the ordinary state: most projects are
 * not worked in a repository at all, and the ones that are get the field filled
 * in by hand on the Manage screen. Nothing branches on the null beyond the
 * resolution chain declining to match, which is why this column does not join
 * the null-semantics registry - that audit (#115) covers the four billing
 * tables, where a null selects a branch in money arithmetic. This one selects
 * nothing; it is read, compared for equality, and otherwise ignored.
 *
 * ## Stored normalized, deliberately
 *
 * The value is the canonical `host/owner/name` produced by
 * {@see RepositoryReference}, because one remote has at least five
 * spellings and a mapping that only matched the spelling the operator happened
 * to paste would be worse than no mapping: it would fail quietly. Normalization
 * happens on the way in rather than on every read, so the stored value is
 * directly comparable and a client needs no parser of its own beyond the same
 * rules applied to its own remote.
 *
 * ## No uniqueness constraint, and no index
 *
 * **Not unique**, even scoped to the workspace. One repository billed to two
 * projects is a real situation - a monorepo serving two engagements, or a
 * client project and an internal one sharing a checkout - and a database
 * constraint would forbid it outright. Ambiguity is resolved by asking the
 * operator which project they meant, which is also what happens today when
 * nothing matches, so the degraded path already exists and is safe.
 *
 * **Not indexed**, because nothing queries by it. Resolution happens in the
 * client: it reads the workspace's projects, which it needs anyway to show a
 * chooser, and compares in memory. An index here would serve a query that does
 * not exist. If a server-side `repository=` filter is ever added to
 * `projects.list`, it arrives with its own index and its own justification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_projects', function (Blueprint $table): void {
            $table->string('repository', 255)
                ->nullable()
                ->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('client_projects', function (Blueprint $table): void {
            $table->dropColumn('repository');
        });
    }
};
