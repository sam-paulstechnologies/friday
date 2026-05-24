<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LifeOsPlaceholderSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::orderBy('id')->first();

        if (! $user) {
            $user = User::firstOrCreate(
                ['email' => 'test@example.com'],
                [
                    'name' => 'Test User',
                    'password' => 'password',
                ],
            );
        }

        $workspace = Workspace::orderBy('id')->first();

        if (! $workspace) {
            $workspace = Workspace::firstOrCreate(
                ['slug' => 'taskflow-workspace'],
                [
                    'name' => 'Friday Workspace',
                    'created_by' => $user->id,
                ],
            );
        }

        Team::firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'slug' => 'product-team',
            ],
            [
                'name' => 'Product Team',
                'description' => 'Default team for early Friday planning and setup.',
            ],
        );

        $areaDefinitions = [
            ['name' => 'Career', 'color' => '#2563eb', 'icon' => 'briefcase'],
            ['name' => 'Helping Others', 'color' => '#059669', 'icon' => 'hands'],
            ['name' => 'Personal Foundation', 'color' => '#ea580c', 'icon' => 'foundation'],
            ['name' => 'Finance & Assets', 'color' => '#0f766e', 'icon' => 'finance'],
            ['name' => 'CEO Command Center', 'color' => '#111827', 'icon' => 'command'],
            ['name' => 'Ventures', 'color' => '#7c3aed', 'icon' => 'rocket'],
        ];

        $portfolioGroups = [
            'Career' => [
                'Publicis Digitas',
                'Stellantis GCC',
                'Stellantis South Africa',
                'Digitas Internal',
                'Reporting & Automation',
                'Career Growth',
            ],
            'Helping Others' => [
                'The Pauls Technologies',
                'UAE Realtor Agents App',
                'Family / Other Requests',
            ],
            'Personal Foundation' => [
                'Spirituality',
                'Health & Fitness',
                'Family Life',
                'Learning',
                'Personal Admin',
            ],
            'Finance & Assets' => [
                'Monthly Budget',
                'Loans',
                'Net Worth',
                'Dubai House Goal',
                'Emergency Fund',
            ],
            'CEO Command Center' => [
                'Daily Focus',
                'Weekly Focus',
                'Decisions',
                'Waiting For',
                'Risks',
                'Approvals',
            ],
            'Ventures' => [
                'SayaraForce',
                'ChurchForce',
                'LifeBoard / JARVIS',
                'Future SaaS Ideas',
            ],
        ];

        $createdAreas = 0;
        $updatedAreas = 0;
        $createdPortfolios = 0;
        $updatedPortfolios = 0;
        $areas = [];

        foreach ($areaDefinitions as $index => $areaDefinition) {
            $area = Area::updateOrCreate(
                ['slug' => Str::slug($areaDefinition['name'])],
                [
                    'name' => $areaDefinition['name'],
                    'description' => "{$areaDefinition['name']} operating area.",
                    'color' => $areaDefinition['color'],
                    'icon' => $areaDefinition['icon'],
                    'position' => $index + 1,
                    'is_active' => true,
                ],
            );

            $area->wasRecentlyCreated ? $createdAreas++ : $updatedAreas++;
            $areas[$areaDefinition['name']] = $area;
        }

        foreach ($portfolioGroups as $areaName => $portfolioNames) {
            $area = $areas[$areaName];

            foreach ($portfolioNames as $index => $portfolioName) {
                $slug = Str::slug($portfolioName);
                $existingPortfolio = Portfolio::where('slug', $slug)->first();
                $values = [
                    'area_id' => $area->id,
                    'workspace_id' => $workspace->id,
                    'name' => $portfolioName,
                    'color' => $area->color,
                    'icon' => $area->icon,
                    'status' => 'active',
                    'position' => $index + 1,
                ];

                if (! in_array($portfolioName, ['SayaraForce', 'ChurchForce'], true) || blank($existingPortfolio?->description)) {
                    $values['description'] = "{$portfolioName} portfolio.";
                }

                $portfolio = Portfolio::updateOrCreate(
                    ['slug' => $slug],
                    $values,
                );

                $portfolio->wasRecentlyCreated ? $createdPortfolios++ : $updatedPortfolios++;
            }
        }

        $this->command?->info(sprintf(
            'Life OS placeholders synced. Areas created: %d, areas updated: %d, portfolios created: %d, portfolios updated: %d.',
            $createdAreas,
            $updatedAreas,
            $createdPortfolios,
            $updatedPortfolios,
        ));
    }
}
