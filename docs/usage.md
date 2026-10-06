# Usage

### 1. Console Command (CLI & CI)

```bash
php bin/console seo:check [url] [--max-depth=N] [--max-pages=N] [--exclude=PATTERN ...] \
    [--only=links,on_page,technical] [--no-external] [--fail-on=error|warning|notice]
```

The `url` argument is optional if `seo.base_url` is configured. `--only` restricts the audit to some of the enabled
modules for that run.

> [!TIP]
> **CI / Exit Codes:** every issue is always printed, but only issues at or above `--fail-on` (default: `error`) make
> the command exit with `1`. That way a warning-level finding shows up in the build log without breaking the pipeline,
> and you can tighten the threshold once the site is clean.

| Exit code | When                                                                                  |
|-----------|---------------------------------------------------------------------------------------|
| `0`       | No issue at or above `--fail-on`, or another `seo:check` was already running (below)  |
| `1`       | An issue at or above `--fail-on`, or the start URL answers an error or is unreachable |
| `2`       | Invalid input: no URL at all, or a wrong `--fail-on`, `--only` or `--exclude` value   |

> [!WARNING]
> Only one `seo:check` runs at a time on a machine. A second one started while the first is still running prints a
> warning and exits with `0` without auditing anything, so a CI job or a cron overlapping a previous run passes without
> having checked the site. Space out the runs, or look for "already running" in the output.

### 2. Asynchronous Execution (Messenger)

```php
use Lbonnet\SeoBundle\Message\CheckSeoMessage;
use Symfony\Component\Messenger\MessageBusInterface;

public function triggerAudit(MessageBusInterface $bus): void
{
    $bus->dispatch(new CheckSeoMessage());

    // Or with custom parameters
    $bus->dispatch(new CheckSeoMessage(
        startUrl: 'https://example.com/blog',
        maxDepth: 2,
        excludePatterns: ['#/preview#'],
        maxPages: 100,
        modules: ['links', 'technical'],
        checkExternal: false,
    ));
}
```

Every argument left out falls back to the bundle configuration, as the command options do; `checkExternal: false` is
the equivalent of `--no-external`, and `excludePatterns` adds to `crawl.exclude_patterns` like `--exclude`. A message
with an invalid URL, pattern or module is logged as an error and dropped, without auditing anything.

The audit only runs outside the request if the message goes through an asynchronous transport. Without a route,
Messenger handles it synchronously, in the request that dispatched it:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            Lbonnet\SeoBundle\Message\CheckSeoMessage: async
```

### 3. Automated Monitoring (Symfony Scheduler)

```php
namespace App\Scheduler;

use Lbonnet\SeoBundle\Message\CheckSeoMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                // Run daily at 03:00 AM
                RecurringMessage::cron('0 3 * * *', new CheckSeoMessage())
            );
    }
}
```

### 4. Custom Notifications & Event Handling

When an audit completes, a `SeoAuditCompletedEvent` is dispatched:

```php
namespace App\EventListener;

use Lbonnet\SeoBundle\Event\SeoAuditCompletedEvent;
use Lbonnet\SeoBundle\Model\Severity;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;

#[AsEventListener]
final class SeoNotificationListener
{
    public function __construct(
        private readonly NotifierInterface $notifier,
    ) {
    }

    public function __invoke(SeoAuditCompletedEvent $event): void
    {
        $report = $event->report;

        if (!$report->hasIssues(Severity::Error)) {
            return;
        }

        $message = sprintf(
            'Found %d error(s) on %s (read %d page(s) in %.2fs).',
            $report->getIssuesCount(Severity::Error),
            $report->startUrl,
            $report->pagesRead,
            $report->totalDuration
        );

        $this->notifier->send(new Notification($message, ['chat/slack', 'email']));
    }
}
```

The event carries the whole report, so any other use — a CSV export, a POST to an internal API, a daily digest — is a
listener of this shape. To replace the stored JSON rather than add to it, implement `ReportStorageInterface` and point
the interface alias at your own service.
