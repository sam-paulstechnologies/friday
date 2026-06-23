<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create Development Manager support tables that were present locally before
     * the ledger rollout, but may be missing on partially migrated production.
     */
    public function up(): void
    {
        $this->createPromptOsTables();
        $this->createRunnerAndDevelopmentTables();
        $this->createFailureTables();
        $this->createAppRegistryTables();
        $this->createReleaseTables();
        $this->addRegistryColumnsToDevelopmentTables();
    }

    public function down(): void
    {
        // Production-safe compatibility migration: do not drop history.
    }

    private function createPromptOsTables(): void
    {
        if (! Schema::hasTable('miriam_prompt_programs')) {
            Schema::create('miriam_prompt_programs', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->longText('vision_markdown')->nullable();
                $table->string('status')->default('active');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['status', 'sort_order'], 'mppo_status_sort_idx');
            });
        }

        if (! Schema::hasTable('miriam_prompt_phases')) {
            Schema::create('miriam_prompt_phases', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_program_id')->nullable();
                $table->string('phase_key')->nullable();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('status')->default('queued');
                $table->unsignedBigInteger('depends_on_phase_id')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->longText('acceptance_criteria')->nullable();
                $table->longText('safety_notes')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['prompt_program_id', 'phase_key'], 'mpph_program_phase_unique');
                $table->index(['prompt_program_id', 'status', 'sort_order'], 'mpph_program_status_sort_idx');
            });
        }

        if (! Schema::hasTable('miriam_saved_prompts')) {
            Schema::create('miriam_saved_prompts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_program_id')->nullable();
                $table->unsignedBigInteger('prompt_phase_id')->nullable();
                $table->string('type');
                $table->string('title');
                $table->longText('body');
                $table->longText('variables_json')->nullable();
                $table->string('status')->default('active');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['prompt_program_id', 'status', 'sort_order'], 'msp_program_status_sort_idx');
                $table->index(['prompt_phase_id', 'type', 'status'], 'msp_phase_type_status_idx');
            });
        }
    }

    private function createRunnerAndDevelopmentTables(): void
    {
        if (! Schema::hasTable('miriam_runner_agents')) {
            Schema::create('miriam_runner_agents', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->string('token_hash', 128);
                $table->string('machine_name')->nullable();
                $table->string('os')->nullable();
                $table->string('local_project_path')->nullable();
                $table->string('status')->default('inactive');
                $table->timestamp('last_seen_at')->nullable();
                $table->string('last_ip')->nullable();
                $table->longText('capabilities_json')->nullable();
                $table->longText('config_json')->nullable();
                $table->timestamps();

                $table->index(['status', 'last_seen_at'], 'mra_status_seen_idx');
                $table->index('token_hash', 'mra_token_hash_idx');
            });
        }

        if (! Schema::hasTable('miriam_development_jobs')) {
            Schema::create('miriam_development_jobs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_program_id')->nullable();
                $table->unsignedBigInteger('managed_app_id')->nullable();
                $table->unsignedBigInteger('validation_profile_id')->nullable();
                $table->unsignedBigInteger('runner_agent_id')->nullable();
                $table->unsignedBigInteger('started_by_user_id')->nullable();
                $table->string('title');
                $table->string('status')->default('queued');
                $table->unsignedBigInteger('current_phase_id')->nullable();
                $table->unsignedInteger('total_phases')->default(0);
                $table->unsignedInteger('completed_phases')->default(0);
                $table->unsignedBigInteger('failed_phase_id')->nullable();
                $table->string('run_mode')->default('all_phases');
                $table->string('local_project_path_snapshot')->nullable();
                $table->string('local_url_snapshot')->nullable();
                $table->longText('options_json')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('error_message')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at'], 'mdj_status_created_idx');
                $table->index(['runner_agent_id', 'status'], 'mdj_runner_status_idx');
                $table->index(['managed_app_id', 'status'], 'mdj_app_status_idx');
            });
        }

        if (! Schema::hasTable('miriam_development_phase_runs')) {
            Schema::create('miriam_development_phase_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('development_job_id');
                $table->unsignedBigInteger('managed_app_id')->nullable();
                $table->unsignedBigInteger('validation_profile_id')->nullable();
                $table->unsignedBigInteger('prompt_program_id')->nullable();
                $table->unsignedBigInteger('prompt_phase_id')->nullable();
                $table->unsignedBigInteger('saved_prompt_id')->nullable();
                $table->unsignedBigInteger('runner_agent_id')->nullable();
                $table->unsignedInteger('phase_order')->default(0);
                $table->string('status')->default('queued');
                $table->longText('prompt_body');
                $table->longText('runner_instruction_json')->nullable();
                $table->string('local_project_path_snapshot')->nullable();
                $table->string('local_url_snapshot')->nullable();
                $table->longText('codex_stdout')->nullable();
                $table->longText('codex_stderr')->nullable();
                $table->longText('parsed_result_json')->nullable();
                $table->longText('validation_json')->nullable();
                $table->longText('files_changed_json')->nullable();
                $table->longText('backup_paths_json')->nullable();
                $table->longText('manifest_before_json')->nullable();
                $table->longText('manifest_after_json')->nullable();
                $table->string('release_package_path')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('error_message')->nullable();
                $table->timestamps();

                $table->index(['development_job_id', 'phase_order'], 'mdpr_job_order_idx');
                $table->index(['runner_agent_id', 'status'], 'mdpr_runner_status_idx');
                $table->index(['managed_app_id', 'status'], 'mdpr_app_status_idx');
            });
        }

        if (! Schema::hasTable('miriam_development_job_events')) {
            Schema::create('miriam_development_job_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('development_job_id')->nullable();
                $table->unsignedBigInteger('phase_run_id')->nullable();
                $table->unsignedBigInteger('runner_agent_id')->nullable();
                $table->string('event_type');
                $table->string('title');
                $table->longText('body')->nullable();
                $table->longText('meta_json')->nullable();
                $table->timestamps();

                $table->index(['development_job_id', 'created_at'], 'mdje_job_created_idx');
                $table->index(['event_type', 'created_at'], 'mdje_type_created_idx');
            });
        }
    }

    private function createFailureTables(): void
    {
        if (! Schema::hasTable('miriam_development_failures')) {
            Schema::create('miriam_development_failures', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('development_job_id');
                $table->unsignedBigInteger('phase_run_id')->nullable();
                $table->unsignedBigInteger('runner_agent_id')->nullable();
                $table->string('failure_type')->nullable();
                $table->string('severity')->default('medium');
                $table->string('title');
                $table->longText('summary')->nullable();
                $table->string('command')->nullable();
                $table->string('file_path')->nullable();
                $table->longText('error_excerpt')->nullable();
                $table->string('full_error_path')->nullable();
                $table->boolean('can_auto_fix')->default(false);
                $table->boolean('needs_user_at_system')->default(false);
                $table->string('status')->default('open');
                $table->string('slack_channel_id')->nullable();
                $table->string('slack_message_ts')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['development_job_id', 'status'], 'mdf_job_status_idx');
                $table->index(['failure_type', 'status'], 'mdf_type_status_idx');
            });
        }

        if (! Schema::hasTable('miriam_development_fix_attempts')) {
            Schema::create('miriam_development_fix_attempts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('development_failure_id');
                $table->unsignedBigInteger('development_job_id');
                $table->unsignedBigInteger('phase_run_id')->nullable();
                $table->unsignedBigInteger('runner_agent_id')->nullable();
                $table->unsignedInteger('attempt_number')->default(1);
                $table->string('status')->default('queued');
                $table->longText('fix_prompt');
                $table->longText('codex_stdout')->nullable();
                $table->longText('codex_stderr')->nullable();
                $table->longText('validation_json')->nullable();
                $table->longText('files_changed_json')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('error_message')->nullable();
                $table->timestamps();

                $table->index(['development_failure_id', 'status'], 'mdfa_failure_status_idx');
                $table->index(['development_job_id', 'status'], 'mdfa_job_status_idx');
            });
        }
    }

    private function createAppRegistryTables(): void
    {
        if (! Schema::hasTable('miriam_managed_apps')) {
            Schema::create('miriam_managed_apps', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('app_type')->nullable();
                $table->string('tech_stack')->nullable();
                $table->string('status')->default('active');
                $table->unsignedBigInteger('prompt_program_id')->nullable();
                $table->unsignedBigInteger('default_runner_agent_id')->nullable();
                $table->string('local_project_path')->nullable();
                $table->string('local_url')->nullable();
                $table->string('cloud_url')->nullable();
                $table->string('super_admin_url')->nullable();
                $table->string('backup_path')->nullable();
                $table->string('release_path')->nullable();
                $table->longText('notes')->nullable();
                $table->longText('config_json')->nullable();
                $table->timestamps();

                $table->index(['status', 'slug'], 'mma_status_slug_idx');
                $table->index('prompt_program_id', 'mma_prompt_program_idx');
                $table->index('default_runner_agent_id', 'mma_runner_idx');
            });
        }

        if (! Schema::hasTable('miriam_app_validation_profiles')) {
            Schema::create('miriam_app_validation_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('managed_app_id');
                $table->string('name');
                $table->string('slug');
                $table->string('stack_type')->nullable();
                $table->longText('commands_json');
                $table->longText('frontend_change_patterns_json')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();

                $table->unique(['managed_app_id', 'slug'], 'mavp_app_slug_unique');
                $table->index(['managed_app_id', 'status'], 'mavp_app_status_idx');
            });
        }
    }

    private function createReleaseTables(): void
    {
        if (! Schema::hasTable('miriam_release_packages')) {
            Schema::create('miriam_release_packages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('managed_app_id')->nullable();
                $table->unsignedBigInteger('development_job_id')->nullable();
                $table->unsignedBigInteger('runner_agent_id')->nullable();
                $table->string('title');
                $table->string('version_label')->nullable();
                $table->string('status')->default('draft');
                $table->string('package_path')->nullable();
                $table->unsignedBigInteger('package_size_bytes')->nullable();
                $table->longText('manifest_json')->nullable();
                $table->longText('files_included_json')->nullable();
                $table->longText('files_excluded_json')->nullable();
                $table->longText('validation_summary_json')->nullable();
                $table->longText('notes')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->timestamp('packaged_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->string('error_message')->nullable();
                $table->timestamps();

                $table->index(['managed_app_id', 'status'], 'mrp_app_status_idx');
                $table->index(['development_job_id', 'status'], 'mrp_job_status_idx');
                $table->index(['runner_agent_id', 'status'], 'mrp_runner_status_idx');
            });
        }

        if (! Schema::hasTable('miriam_release_approvals')) {
            Schema::create('miriam_release_approvals', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('release_package_id');
                $table->unsignedBigInteger('managed_app_id')->nullable();
                $table->unsignedBigInteger('development_job_id')->nullable();
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->string('status')->default('pending');
                $table->string('risk_level')->default('medium');
                $table->string('title');
                $table->longText('description')->nullable();
                $table->string('decision_note')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();

                $table->index(['release_package_id', 'status'], 'mra_pkg_status_idx');
                $table->index(['managed_app_id', 'status'], 'mra_app_status_idx');
            });
        }
    }

    private function addRegistryColumnsToDevelopmentTables(): void
    {
        if (Schema::hasTable('miriam_development_jobs')) {
            Schema::table('miriam_development_jobs', function (Blueprint $table): void {
                if (! Schema::hasColumn('miriam_development_jobs', 'managed_app_id')) {
                    $table->unsignedBigInteger('managed_app_id')->nullable()->after('prompt_program_id');
                    $table->index(['managed_app_id', 'status'], 'mdj_app_status_idx');
                }

                if (! Schema::hasColumn('miriam_development_jobs', 'validation_profile_id')) {
                    $table->unsignedBigInteger('validation_profile_id')->nullable()->after('managed_app_id');
                }

                if (! Schema::hasColumn('miriam_development_jobs', 'local_project_path_snapshot')) {
                    $table->string('local_project_path_snapshot')->nullable()->after('run_mode');
                }

                if (! Schema::hasColumn('miriam_development_jobs', 'local_url_snapshot')) {
                    $table->string('local_url_snapshot')->nullable()->after('local_project_path_snapshot');
                }
            });
        }

        if (Schema::hasTable('miriam_development_phase_runs')) {
            Schema::table('miriam_development_phase_runs', function (Blueprint $table): void {
                if (! Schema::hasColumn('miriam_development_phase_runs', 'managed_app_id')) {
                    $table->unsignedBigInteger('managed_app_id')->nullable()->after('development_job_id');
                    $table->index(['managed_app_id', 'status'], 'mdpr_app_status_idx');
                }

                if (! Schema::hasColumn('miriam_development_phase_runs', 'validation_profile_id')) {
                    $table->unsignedBigInteger('validation_profile_id')->nullable()->after('managed_app_id');
                }

                if (! Schema::hasColumn('miriam_development_phase_runs', 'local_project_path_snapshot')) {
                    $table->string('local_project_path_snapshot')->nullable()->after('runner_instruction_json');
                }

                if (! Schema::hasColumn('miriam_development_phase_runs', 'local_url_snapshot')) {
                    $table->string('local_url_snapshot')->nullable()->after('local_project_path_snapshot');
                }
            });
        }
    }
};
