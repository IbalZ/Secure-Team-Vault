<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditChainVerifier;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTimestampTest extends TestCase
{
    use RefreshDatabase;

    public function test_hydrated_audit_timestamp_is_utc_and_displays_the_correct_malaysia_time(): void
    {
        $log = new AuditLog;
        $log->setRawAttributes(['occurred_at' => '2026-10-07 02:19:22.362990']);

        $this->assertSame('UTC', $log->occurred_at->timezoneName);
        $this->assertSame('2026-10-07T02:19:22.362990Z', $log->occurred_at->format('Y-m-d\TH:i:s.u\Z'));
        $this->assertSame('07 Oct 2026, 10:19:22', $log->occurred_at->setTimezone('Asia/Kuala_Lumpur')->format('d M Y, H:i:s'));
    }

    public function test_timestamp_assignment_converts_local_time_to_utc_without_losing_microseconds(): void
    {
        $log = new AuditLog;
        $log->occurred_at = CarbonImmutable::parse('2026-10-07 10:19:22.362990', 'Asia/Kuala_Lumpur');

        $this->assertSame('2026-10-07 02:19:22.362990', $log->getAttributes()['occurred_at']);
        $this->assertSame('UTC', $log->occurred_at->timezoneName);
    }

    public function test_audit_page_displays_local_time_without_modifying_the_database_or_integrity(): void
    {
        $admin = $this->admin();
        $this->travelTo(CarbonImmutable::parse('2026-10-07T02:19:22.362990Z'));
        $event = app(AuditLogger::class)->record($admin, 'timezone.display_check');
        $before = $event->fresh()->getRawOriginal();

        $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk()
            ->assertSee('07 Oct 2026, 10:19:22')
            ->assertSee('Asia/Kuala_Lumpur')
            ->assertSee('UTC+08:00')
            ->assertSee('Chain verified');

        $this->assertSame($before, $event->fresh()->getRawOriginal());
        $this->assertTrue(app(AuditChainVerifier::class)->verify()['valid']);
        $this->assertSame('UTC', $event->fresh()->occurred_at->timezoneName);
    }

    public function test_date_filter_uses_the_malaysia_day_including_its_utc_boundaries(): void
    {
        $admin = $this->admin();
        foreach ([
            ['2026-10-06T15:59:59.999999Z', 'timezone.previous_day'],
            ['2026-10-06T16:00:00.000000Z', 'timezone.local_day_start'],
            ['2026-10-07T15:59:59.999999Z', 'timezone.local_day_end'],
            ['2026-10-07T16:00:00.000000Z', 'timezone.next_day'],
        ] as [$at, $action]) {
            $this->travelTo(CarbonImmutable::parse($at));
            app(AuditLogger::class)->record($admin, $action);
        }

        $this->actingAs($admin)->get(route('admin.audit.index', ['date' => '2026-10-07']))
            ->assertOk()->assertViewHas('logs', function ($logs): bool {
                return $logs->pluck('action')->all() === ['timezone.local_day_end', 'timezone.local_day_start'];
            });
        $this->assertTrue(app(AuditChainVerifier::class)->verify()['valid']);
    }

    public function test_relative_time_is_one_minute_ago_not_eight_hours_ago(): void
    {
        $admin = $this->admin();
        $this->travelTo(CarbonImmutable::parse('2026-10-07T02:19:22Z'));
        $event = app(AuditLogger::class)->record($admin, 'timezone.relative_check');
        $this->travelTo(CarbonImmutable::parse('2026-10-07T02:20:22Z'));

        $this->assertSame('1 minute ago', $event->fresh()->occurred_at->diffForHumans());
        $this->assertTrue(app(AuditChainVerifier::class)->verify()['valid']);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin', 'is_active' => true, 'account_expires_at' => null,
        ]);
    }
}
