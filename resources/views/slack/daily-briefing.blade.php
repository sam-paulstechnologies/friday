<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { margin: 0; background: #f8fafc; color: #0f172a; font-family: Arial, sans-serif; }
        .briefing { width: 1400px; padding: 34px 40px; box-sizing: border-box; }
        .muted { color: #475569; }
        .summary { color: #2563eb; font-weight: 700; margin-top: 12px; }
        .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 30px; margin-top: 36px; }
        .card, .section { background: #fff; border: 1px solid #cbd5e1; padding: 20px; }
        .kpi-value { font-size: 32px; font-weight: 700; margin-top: 8px; }
        .portfolio-summary { margin-top: 22px; }
        .portfolio-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-top: 18px; }
        .sections { margin-top: 22px; display: grid; gap: 18px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        th { text-align: left; color: #475569; font-size: 12px; }
        td { padding-top: 7px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        footer { margin-top: 20px; display: flex; justify-content: space-between; color: #475569; font-size: 13px; }
    </style>
</head>
<body>
    <main class="briefing">
        <h1>{{ $briefing['title'] }}</h1>
        <div class="muted">Today: {{ $briefing['date'] }} | {{ $briefing['portfolio_label'] }}</div>
        <div class="summary">{{ $briefing['summary_line'] }}</div>

        <section class="kpis">
            @foreach ([['Open', 'open'], ['Overdue', 'overdue'], ['Due today', 'due_today'], ['Due this week', 'due_week']] as [$label, $key])
                <div class="card">
                    <div class="muted">{{ $label }}</div>
                    <div class="kpi-value">{{ $briefing['summary'][$key] }}</div>
                </div>
            @endforeach
        </section>

        <section class="card portfolio-summary">
            <h2>Portfolio Summary</h2>
            <div class="portfolio-grid">
                @foreach ($briefing['portfolio_summary'] as $portfolio)
                    <div>
                        <strong>{{ $portfolio['portfolio'] }}</strong>
                        <div class="muted">Open {{ $portfolio['open'] }} | Overdue {{ $portfolio['overdue'] }} | Due today {{ $portfolio['due_today'] }} | Urgent/high {{ $portfolio['urgent_high'] }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="sections">
            @foreach (['focus' => 'Focus', 'overdue' => 'Overdue', 'due_today' => 'Due Today', 'upcoming' => 'Upcoming'] as $key => $label)
                <div class="section">
                    <h2>{{ $label }}</h2>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 4%">No.</th>
                                <th style="width: 9%">Type</th>
                                <th style="width: 8%">Priority</th>
                                <th style="width: 10%">Due</th>
                                <th style="width: 13%">Portfolio</th>
                                <th style="width: 23%">Project</th>
                                <th>Task</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($briefing['sections'][$key] as $task)
                                <tr>
                                    <td>{{ $task['no'] }}</td>
                                    <td>{{ $task['type'] }}</td>
                                    <td>{{ $task['priority'] }}</td>
                                    <td>{{ $task['due'] }}</td>
                                    <td>{{ $task['portfolio'] }}</td>
                                    <td>{{ $task['project'] }}</td>
                                    <td>{{ $task['task'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="muted">No matching tasks</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
        </section>

        <footer>
            <span>{{ $briefing['priority_label'] }}</span>
            <span>See full details in Miriam Reports / Workload</span>
        </footer>
    </main>
</body>
</html>
