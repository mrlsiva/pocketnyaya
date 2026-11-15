<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie Permission tables in teams mode, with the approved C26 safeguards.
 * team_id is a tenant UUID, and only users can hold roles or permissions.
 */
return new class extends Migration
{
    private const TRIGGERS = [
        'mhr_guard_ins', 'mhr_guard_upd', 'mhp_guard_ins', 'mhp_guard_upd',
        'rhp_guard_ins', 'rhp_guard_upd', 'roles_guard_ins', 'roles_guard_upd',
        'membership_revoke_upd', 'membership_revoke_del', 'tenant_type_guard',
    ];

    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 125);
            $table->string('guard_name', 125);
            $table->string('scope', 10);
            $table->datetimes(6);
            $table->unique(['name', 'guard_name']);
        });
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_scope_chk CHECK (scope IN ('platform','tenant'))");

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->pnUuid('team_id')->nullable();
            $table->string('name', 125);
            $table->string('guard_name', 125);
            $table->string('scope', 10);
            $table->boolean('requires_membership')->default(true);
            $table->datetimes(6);
            // Replaces the PostgreSQL partial unique index for global roles.
            // Set by trigger: MariaDB 10.11 refuses this as a stored generated column (error 1901).
            $table->string('team_key', 36)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->index('team_id');
            $table->unique(['team_key', 'name', 'guard_name'], 'roles_team_name_guard_uq');
            $table->foreign('team_id')->references('id')->on('tenants');
        });
        foreach (['INSERT' => 'bi', 'UPDATE' => 'bu'] as $event => $suffix) {
            DB::unprepared("CREATE TRIGGER roles_keys_{$suffix} BEFORE {$event} ON roles FOR EACH ROW SET NEW.team_key = COALESCE(NEW.team_id, 'GLOBAL')");
        }
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_chk CHECK (scope IN ('platform','tenant'))");
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_custom_is_tenant_scope CHECK (team_id IS NULL OR scope = 'tenant')");
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_tenant_needs_membership CHECK (scope = 'platform' OR requires_membership = 1)");

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 125);
            $table->pnUuid('model_id');
            $table->pnUuid('team_id');

            $table->primary(['team_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_primary');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_idx');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('model_id', 'mhp_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('team_id', 'mhp_team_fk')->references('id')->on('tenants')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE model_has_permissions ADD CONSTRAINT mhp_user_only CHECK (model_type = 'user')");

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type', 125);
            $table->pnUuid('model_id');
            $table->pnUuid('team_id');

            $table->primary(['team_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_primary');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_idx');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('model_id', 'mhr_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('team_id', 'mhr_team_fk')->references('id')->on('tenants')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE model_has_roles ADD CONSTRAINT mhr_user_only CHECK (model_type = 'user')");

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->primary(['permission_id', 'role_id']);
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        $this->createGuards();
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        DB::unprepared('DROP PROCEDURE IF EXISTS pn_assert_assignment');

        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }

    private function createGuards(): void
    {
        // MariaDB 10.4 has no FOR SHARE, so LOCK IN SHARE MODE gives the same row lock.
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE pn_assert_assignment(
    IN p_team CHAR(36) CHARACTER SET ascii,
    IN p_user CHAR(36) CHARACTER SET ascii,
    IN p_scope VARCHAR(10),
    IN p_def_team CHAR(36) CHARACTER SET ascii,
    IN p_requires_membership TINYINT
)
BEGIN
    DECLARE v_type VARCHAR(10) DEFAULT NULL;
    DECLARE v_members INT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_type = NULL;

    SELECT type INTO v_type FROM tenants
     WHERE id = p_team AND status = 'active' AND deleted_at IS NULL
     LOCK IN SHARE MODE;

    IF v_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_RBAC_TENANT_INACTIVE: assignment tenant is not active';
    END IF;
    IF p_def_team IS NOT NULL AND p_def_team <> p_team THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_RBAC_CROSS_TENANT_ROLE: role belongs to another tenant';
    END IF;
    IF p_scope = 'platform' AND v_type <> 'platform' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_RBAC_SCOPE: platform grant outside the platform tenant';
    END IF;
    IF p_scope = 'tenant' AND v_type NOT IN ('solo', 'firm') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_RBAC_SCOPE: tenant grant outside a solo or firm tenant';
    END IF;
    IF p_requires_membership = 1 THEN
        SELECT COUNT(*) INTO v_members FROM tenant_memberships
         WHERE tenant_id = p_team AND user_id = p_user
           AND status = 'active' AND deleted_at IS NULL
         LOCK IN SHARE MODE;
        IF v_members = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_RBAC_NO_MEMBERSHIP: user has no active membership in this tenant';
        END IF;
    END IF;
END
SQL);

        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $suffix => $event) {
            DB::unprepared(<<<SQL
CREATE TRIGGER mhr_guard_{$suffix} BEFORE {$event} ON model_has_roles FOR EACH ROW
BEGIN
    DECLARE v_scope VARCHAR(10);
    DECLARE v_def_team CHAR(36) CHARACTER SET ascii;
    DECLARE v_requires TINYINT;
    SELECT scope, team_id, requires_membership INTO v_scope, v_def_team, v_requires FROM roles WHERE id = NEW.role_id;
    CALL pn_assert_assignment(NEW.team_id, NEW.model_id, v_scope, v_def_team, v_requires);
END
SQL);

            DB::unprepared(<<<SQL
CREATE TRIGGER mhp_guard_{$suffix} BEFORE {$event} ON model_has_permissions FOR EACH ROW
BEGIN
    DECLARE v_scope VARCHAR(10);
    SELECT scope INTO v_scope FROM permissions WHERE id = NEW.permission_id;
    CALL pn_assert_assignment(NEW.team_id, NEW.model_id, v_scope, NULL, 1);
END
SQL);

            DB::unprepared(<<<SQL
CREATE TRIGGER rhp_guard_{$suffix} BEFORE {$event} ON role_has_permissions FOR EACH ROW
BEGIN
    IF (SELECT scope FROM roles WHERE id = NEW.role_id) <> (SELECT scope FROM permissions WHERE id = NEW.permission_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_RBAC_PERMISSION_SCOPE: role and permission scopes differ';
    END IF;
END
SQL);
        }

        $ownerCheck = <<<'SQL'
    IF NEW.team_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tenants WHERE id = NEW.team_id AND type IN ('solo', 'firm')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_RBAC_ROLE_OWNER: custom roles may only belong to solo or firm tenants';
    END IF;
SQL;

        DB::unprepared(<<<SQL
CREATE TRIGGER roles_guard_ins BEFORE INSERT ON roles FOR EACH ROW
BEGIN
{$ownerCheck}
END
SQL);

        DB::unprepared(<<<SQL
CREATE TRIGGER roles_guard_upd BEFORE UPDATE ON roles FOR EACH ROW
BEGIN
    IF NOT (NEW.team_id <=> OLD.team_id) OR NEW.scope <> OLD.scope
       OR NEW.requires_membership <> OLD.requires_membership OR NEW.guard_name <> OLD.guard_name THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_RBAC_ROLE_IMMUTABLE: role team, scope and guard cannot change';
    END IF;
{$ownerCheck}
END
SQL);

        $revoke = <<<'SQL'
        DELETE FROM model_has_roles
         WHERE team_id = OLD.tenant_id AND model_type = 'user' AND model_id = OLD.user_id
           AND role_id IN (SELECT id FROM roles WHERE requires_membership = 1);
        DELETE FROM model_has_permissions
         WHERE team_id = OLD.tenant_id AND model_type = 'user' AND model_id = OLD.user_id;
SQL;

        DB::unprepared(<<<SQL
CREATE TRIGGER membership_revoke_upd AFTER UPDATE ON tenant_memberships FOR EACH ROW
BEGIN
    IF (OLD.status = 'active' AND NEW.status <> 'active')
       OR (OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL) THEN
{$revoke}
    END IF;
END
SQL);

        DB::unprepared(<<<SQL
CREATE TRIGGER membership_revoke_del AFTER DELETE ON tenant_memberships FOR EACH ROW
BEGIN
{$revoke}
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER tenant_type_guard BEFORE UPDATE ON tenants FOR EACH ROW
BEGIN
    IF NEW.type <> OLD.type AND (OLD.type = 'platform' OR NEW.type = 'platform') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PN_TENANT_TYPE: platform tenant type is immutable';
    END IF;
END
SQL);
    }
};
