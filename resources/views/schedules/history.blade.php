@extends('layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">История на разписанието</h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ $group->specialty->name }} · {{ $group->course_year }} курс, група {{ $group->group_name }}
                    · {{ $academicYear }} · {{ $semesterType === 'winter' ? 'зимен' : 'летен' }} семестър
                </p>
            </div>
            <a class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
               href="{{ route('schedules.index', ['group_id' => $group->id, 'academic_year' => $academicYear, 'semester_type' => $semesterType]) }}">
                Към разписанието
            </a>
        </div>
            @if($latest && $previous)
                <section class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Сравнение: версия {{ $previous->revision_no }} → версия {{ $latest->revision_no }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ count($changes['added']) }} добавени ·
                        {{ count($changes['changed']) }} променени ·
                        {{ count($changes['removed']) }} премахнати занятия
                    </p>

                    @if(count($changes['added']) + count($changes['changed']) + count($changes['removed']) === 0)
                        <p class="mt-4 rounded-md bg-gray-50 p-4 text-sm text-gray-600">Съдържанието на разписанието не се е променило.</p>
                    @endif

                    @foreach($changes['added'] as $entry)
                        <p class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-900">
                            Добавено: {{ $entry['subject'] }}, седмица {{ $entry['cycle_week'] }},
                            {{ $weekdays[$entry['weekday_no']] ?? $entry['weekday_no'] }}
                            {{ $entry['start_time'] }}–{{ $entry['end_time'] }}, {{ $entry['room'] ?? 'без зала' }}
                        </p>
                    @endforeach

                    @foreach($changes['changed'] as $change)
                        <div class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-950">
                            <p class="font-medium">Променено: {{ $change['after']['subject'] }}, седмица {{ $change['after']['cycle_week'] }}</p>
                            <p class="mt-1">
                                Преди: {{ $weekdays[$change['before']['weekday_no']] ?? $change['before']['weekday_no'] }}
                                {{ $change['before']['start_time'] }}–{{ $change['before']['end_time'] }},
                                {{ $change['before']['room'] ?? 'без зала' }}
                            </p>
                            <p>
                                След: {{ $weekdays[$change['after']['weekday_no']] ?? $change['after']['weekday_no'] }}
                                {{ $change['after']['start_time'] }}–{{ $change['after']['end_time'] }},
                                {{ $change['after']['room'] ?? 'без зала' }}
                            </p>
                        </div>
                    @endforeach

                    @foreach($changes['removed'] as $entry)
                        <p class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-900">
                            Премахнато: {{ $entry['subject'] }}, седмица {{ $entry['cycle_week'] }},
                            {{ $weekdays[$entry['weekday_no']] ?? $entry['weekday_no'] }}
                            {{ $entry['start_time'] }}–{{ $entry['end_time'] }}
                        </p>
                    @endforeach
                </section>
            @elseif($latest)
                <section class="rounded-lg bg-white p-6 text-sm text-gray-600 shadow-sm ring-1 ring-gray-200">
                    Има една запазена версия. Следващата промяна ще може да бъде сравнена с нея.
                </section>
            @else
                <section class="rounded-lg bg-white p-6 text-sm text-gray-600 shadow-sm ring-1 ring-gray-200">
                    Няма запазена история за тази група, учебна година и семестър.
                </section>
            @endif

            <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="font-semibold text-gray-900">Запазени версии</h3>
                </div>
                <ol class="divide-y divide-gray-200">
                    @forelse($revisions as $revision)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                            <div>
                                <p class="font-medium text-gray-900">
                                    Версия {{ $revision->revision_no }} · {{ $revision->reason }}
                                </p>
                                <p class="mt-1 text-sm text-gray-600">
                                    {{ \Illuminate\Support\Carbon::parse($revision->created_at)->format('d.m.Y H:i') }}
                                    · {{ count($revision->snapshot) }} занятия
                                </p>
                            </div>
                            @if($loop->first)
                                <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-800">Текуща</span>
                            @endif
                        </li>
                    @empty
                        <li class="px-6 py-4 text-sm text-gray-600">Все още няма версии.</li>
                    @endforelse
                </ol>
            </section>
    </div>
@endsection
