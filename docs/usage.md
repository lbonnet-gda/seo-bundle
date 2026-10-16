# Usage

### 1. Console Command (CLI & CI)

```bash
php bin/console seo:check [url] [--max-depth=N] [--max-pages=N] [--exclude=PATTERN ...] \
    [--only=links,on_page,technical] [--no-external] [--fail-on=error|warning|notice]
```

The `url` argument is optional if `seo.base_url` is configured. `--only` restricts the audit to some of the enabled
modules for that run.

> [!TIP]
> **CI / Exit Codes:** only issues at or above `--fail-on` (default: `error`) make the command exit with `1`. That way
> a lower-severity finding shows up in the build log without breaking the pipeline, and you can tighten the threshold
> once the site is clean.

The output opens with a count per check, the worst severity first, which is the whole audit in a dozen lines however big
the site is. The table that follows lists the issues **at or above `--fail-on`** — the ones that decide the exit code
— and a line says how many are counted but not listed. `-v` lists every one of them instead.

What is displayed never changes what fails: `--fail-on` alone decides the exit code, so adding `-v` to a green build
keeps it green.

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
the interface alias at your own service; implement `ReportReaderInterface` as well and audits keep comparing
themselves with what you stored.

### 5. Alerting on What Changed, Not on What Is Wrong

A site audited every morning finds mostly the same issues it found yesterday. `$event->changes` is what the event
has that the report alone has not: the audit compared itself with the previous stored report of that site, so the
listener above can report what appeared instead of repeating the whole list.

```php
use Lbonnet\SeoBundle\Event\SeoAuditCompletedEvent;
use Lbonnet\SeoBundle\Model\IssueChange;

public function __invoke(SeoAuditCompletedEvent $event): void
{
    $changes = $event->changes;

    // null on the first audit of a site, or when storage.dir is disabled.
    if ($changes === null || $changes->appeared === []) {
        return;
    }

    $message = sprintf(
        "%d new issue(s) on %s since %s (%d fixed):\n%s",
        $changes->countAppeared(),
        $event->report->startUrl,
        $changes->previousAuditAt->format('Y-m-d H:i'),
        $changes->countFixed(),
        implode("\n", array_map(
            static fn(IssueChange $change): string => sprintf(
                '%s on %s',
                $change->issue->type->value,
                $change->pageUrl,
            ),
            $changes->appeared,
        )),
    );

    $this->notifier->send(new Notification($message, ['chat/slack']));
}
```

What counts as the same issue from one audit to the next, and why a page not audited again is not a page that was fixed,
is in [Reports](reports.md#what-has-changed-since-the-previous-audit).
