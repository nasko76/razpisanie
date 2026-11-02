<!doctype html>
<html lang="bg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Генериране на разписание</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f6f8; padding: 30px; }
        .card { max-width: 700px; margin: 80px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 15px rgba(0,0,0,0.08); }
        select, button { padding: 12px 16px; font-size: 16px; border-radius: 8px; border: 1px solid #dce3ec; }
        button { background: #1d4ed8; color: white; border: none; cursor: pointer; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Генериране на разписание</h1>

        <form method="POST" action="{{ route('schedules.generate') }}">
            @csrf

            <label for="group_id">Избери група</label>
            <select name="group_id" id="group_id" style="width:100%; margin-bottom: 16px;">
                @foreach($groups as $group)
                    <option value="{{ $group->id }}">
                        {{ $group->specialty->name }} - {{ $group->course_year }} курс - {{ $group->group_name }}
                    </option>
                @endforeach
            </select>

            <button type="submit">Генерирай</button>
        </form>
    </div>
</body>
</html>