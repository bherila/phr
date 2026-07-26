<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>
    <style>
        @page {
            margin: 14mm;
        }

        body {
            color: #111827;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9px;
            line-height: 1.35;
            margin: 0;
        }

        h1 {
            font-size: 18px;
            line-height: 1.2;
            margin: 0 0 4px;
        }

        h2 {
            color: #111827;
            font-size: 12px;
            line-height: 1.25;
            margin: 0 0 5px;
        }

        .generated {
            font-size: 10px;
            margin-bottom: 10px;
        }

        .section {
            margin-top: 11px;
            page-break-inside: avoid;
        }

        .line {
            margin: 0 0 3px;
        }

        .muted {
            color: #646464;
        }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="generated">Generated {{ $generated_at }}</div>

    @foreach ($sections as $section)
        <section class="section">
            <h2>{{ $section['title'] }}</h2>

            @if ($section['is_empty'])
                <div class="line muted">No records.</div>
            @else
                @foreach ($section['rows'] as $row)
                    <div class="line">{{ $row }}</div>
                @endforeach
            @endif

            @if ($section['has_more'])
                <div class="line muted">{{ $overflow_note }}</div>
            @endif
        </section>
    @endforeach
</body>
</html>
