# Symfony Messenger Auto Scaling

![PHP Tests](https://github.com/krakphp/symfony-messenger-auto-scale/workflows/PHP%20Tests/badge.svg?branch=master&event=push)

The Symfony Messenger Auto Scaling package provides the ability to dynamically scale the number of workers for a given set of receivers to respond to dynamic workloads.

It's not uncommon for certain types of workloads to fluctuate throughput for lengthy periods of time that require the number of queue consumers to dynamically scale to meet demand. With this auto scaling package, that is now achievable with symfony's messenger system.

## Installation

Install with composer at `krak/symfony-messenger-auto-scale`.

If symfony's composer install doesn't automatically register the bundle, you can do so manually:

```php
<?php

return [
  //...
  Krak\SymfonyMessengerAutoScale\MessengerAutoScaleBundle::class => ['all' => true],
];
```

## Usage

After the bundle is loaded, you need to configure worker pools which will manage procs for a set of messenger receivers.

```yaml
messenger_auto_scale:
  console_path: '%kernel.project_dir%/tests/Feature/Fixtures/console'
  pools:
    sales:
      min_procs: 0
      max_procs: 5
      receivers: "sales*"
      heartbeat_interval: 5
    default:
      min_procs: 0
      max_procs: 5
      backed_up_alert_threshold: 100
      receivers: "*"
      heartbeat_interval: 10
```

Once configured, you can start the consumer with the `krak:auto-scale:consume` command which will start up and manage the worker pools.

## Matching Receivers

Each pool config must have a `receivers` property which is a simple Glob that will match any of the current transport names setup in the messenger config.

It's important to note, that a receiver can ONLY be apart of one pool. So if two pools have receiver patterns that match the same receiver, then the first defined pool would own that receiver.

### Receiver Priority

By default, if a pool matches more than one receiver, the order in which the receivers are defined in the framework messenger configuration will be the order in which they are consumed.

Let's look at an example:

```yaml
# auto scale config
messenger_auto_scale:
  pools:
    default:
      receivers: '*' # this will match all receivers defined

# messenger config
framework:
  messenger:
    transports:
      transport1: ''
      transport2: ''
      transport3: ''
```

Every worker in the pool will first process messages in transport1, then once empty, they will look at transport2, and so on. Essentially, we're making a call to the messenger consume command like: `console messenger:consume transport1 transport2 transport3`

If you'd like to be a bit more explicit about receiver priority, then you can define the priority option on your transport which will ensure that receivers with the highest priority will get processed before receivers with lower priority. If two receivers have the same priority, then the order in which they are defined will take precedent.

Let's look at an example:

```yaml
# auto scale config
messenger_auto_scale:
  pools:
    default:
      receivers: '*' # this will match all receivers defined

# messenger config
framework:
  messenger:
    transports:
      transport3:
        dsn: ''
        options: { priority: -1 }
      transport1:
        dsn: ''
        options: { priority: 1 }
      transport2: '' # default priority is 0
```

This would have the same effect as the above configuration. Even though the transports are defined in a different order, the priority option ensures they are in the same order as above.

### Disabling Must Match All Receivers

By default, the bundle will throw an exception if any receivers are not matched by the pool config. This is to help prevent any unexpected bugs where you the receiver name is for some reason not matched by a pool when you expected it to.

To disable this check, update the `must_match_all_receivers` config option to false:

```yaml
messenger_auto_scale:
  must_match_all_receivers: false
```

### Custom Worker Command and Options

By default, each worker process starts the default symfony `messenger:consume` command and passes in the receiver ids. You can configure the command to run and any additional options with it.

```yaml
messenger_auto_scale:
  pools:
    default:
      # ...
      worker_command: 'messenger:consume'
      worker_command_options: ['--memory-limit=64M']
```

You can find all of the available options in symfony's worker in the [ConsumeMessagesCommand class](https://github.com/symfony/symfony/blob/5.4/src/Symfony/Component/Messenger/Command/ConsumeMessagesCommand.php#L69).

## Configuring Heartbeats

By default, each worker pool will log a heartbeat event every 60 seconds. If you want to change the frequency of that, you use the pool `heartbeat_interval` to define the number of seconds between subsequent heartbeats.

## Monitoring

You can access the PoolControl from your own services if you want to build out custom monitoring, or you can just use the `krak:auto-scale:pool:*` commands that are registered.

## Auto Scaling

Auto scaling is managed with the AutoScale interface which is responsible for taking the current state of a worker pool captured in the `AutoScaleRequest` and returning the expected num workers for that worker pool captured in `AutoScaleResponse`.

The default auto scale is setup to work off of the current queue size and the configured message rate and then will clip to the min/max procs configured. There also is some logic included to debounce the auto scaling requests to ensure that the system is judicious about when to create new procs and isn't fluctuating too often.

Here is some example config and we'll go over some scenarios:

```yaml
messenger_auto_scale:
  pools:
    catalog:
      max_procs: 5
      message_rate: 100
      scale_up_threshold_seconds: 5
      scale_down_threshold_seconds: 20
      receivers: "catalog"
    sales:
      min_procs:  5
      message_rate: 10
      scale_up_threshold_seconds: 5
      scale_down_threshold_seconds: 20
      receivers: "sales"
```

| Seconds from Start | Catalog Pool Queue Size | Catalog Pool Num Workers | Sales Pool Queue Size | Sales Pool Num Workers | Notes |
| -------------------|-------------------------|--------------------------|-----------------------|------------------------|-------|
| n/a                | 0                       | 0                        | 0                     | 0                      | Initial State |
| 0                  | 0                       | 0                        | 0                     | 5                      | First Run, scaled up to 5 because of min procs |
| 2                  | 1                       | 1                        | 60                    | 5                      | Scale up to 1 on catalog immediately, but wait until scale up threshold for sales |
| 5                  | 0                       | 1                        | 50                    | 5                      | Wait to scale down on for catalog, reset counter for sales for scale up because now a scale up isn't needed |
| 6                  | 0                       | 1                        | 60                    | 5                      | Wait to scale up on sales again, timer started, needs 5 seconds before scale up |
| 11                 | 0                       | 1                        | 60                    | 6                      | Size of queue maintained over 60 for 5 seconds, so now we can scale up. |
| 22                 | 0                       | 0                        | 60                    | 6                      | Catalog now goes back to zero after waiting 20 seconds since needing to scale down |

### Defining your own Auto Scale algorithm

If you want to augment or perform your own auto-scaling algorithm, you can implement the AutoScale interface and then update the `Krak\SymfonyMessengerAutoScale\AutoScale` to point to your new auto scale service. The default service is defined like:

```php
use Krak\SymfonyMessengerAutoScale\AutoScaler;

$autoScale = new AutoScale\MinMaxClipAutoScaler(new AutoScale\DebouncingAutoScaler(new AutoScale\QueueSizeMessageRateAutoScaler()));
```

## Worker Busy Guard & Graceful Shutdown

When scaling down or shutting down, the supervisor needs to avoid killing workers that are mid-message. This is handled via a **busy-file protocol** using PID files on the filesystem.

### How It Works

1. Your application creates an event subscriber that touches a file at `<busy_dir>/<worker-pid>` when a worker starts handling a message, and removes it when the message is handled (or fails).
2. The supervisor checks for this file before killing a worker. If the file exists, the kill is refused — the worker is busy.
3. During shutdown, the supervisor retries every 500ms until workers finish their messages and can be killed normally.

### Configuration

```yaml
messenger_auto_scale:
  busy_dir: '%kernel.project_dir%/var/messenger-workers'
  pools:
    default:
      receivers: '*'
      stop_deadline: 300  # seconds to wait before force-killing (default: null)
      # ...
```

**`busy_dir`** (root-level): Directory where worker PID files are stored. Both the supervisor and the worker event subscriber must use the same directory. Set to `null` to disable busy-file checking (workers can be killed at any time).

**`stop_deadline`** (per-pool): Maximum seconds to wait for busy workers during shutdown before force-killing them.

| Value | Behavior |
|-------|----------|
| `null` (default) | Wait indefinitely — never force-kill. Workers are only stopped once they finish their current message. |
| `0` | Force-kill immediately — no grace period. |
| `300` | Wait up to 5 minutes, then force-kill any remaining workers. |

### Application-Side Setup

You need to create a Symfony Messenger event subscriber in your application that manages the busy files. Example:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

class WorkerBusyGuard
{
    public function __construct(private readonly string $busyDir) {}

    #[AsEventListener(event: WorkerMessageReceivedEvent::class)]
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!is_dir($this->busyDir)) {
            @mkdir($this->busyDir, 0755, true);
        }
        @file_put_contents($this->busyDir . '/' . getmypid(), (string) time());
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        @unlink($this->busyDir . '/' . getmypid());
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        @unlink($this->busyDir . '/' . getmypid());
    }

    #[AsEventListener(event: WorkerStoppedEvent::class)]
    public function onWorkerStopped(): void
    {
        @unlink($this->busyDir . '/' . getmypid());
    }
}
```

Wire the `$busyDir` constructor argument to the same value as the bundle's `busy_dir` config.

### Scale-Down vs Shutdown

Both scale-down and shutdown use the same busy-file guard, but differ in timeout behavior:

- **Scale-down** (normal operation): Uses a 5-second timeout. If workers are still busy after 5 seconds, the supervisor gives up and retries on the next auto-scale cycle.
- **Shutdown** (deployment/SIGTERM): Uses the `stop_deadline` timeout. After the deadline, remaining workers are force-killed (unless `stop_deadline` is `null`).

## Alerts

The alerting system is designed to be flexible and allow each user define alerts as they see. Alerts are simply just events that get dispatched when a certain metric is reached as determined by the services that implement `RaiseAlerts`.

To actually trigger the alerts, you need to run the `krak:auto-scale:alert` command which will check the state of the pools and raise alerts. Put this command on a cron at whatever interval you want alerts monitored at.

### Subscribing to Alerts

You simply just can create a basic symfony event listener/subscriber for that event and you should be able to perform any action on those events.

#### PoolBackedUpAlert

This alert will fire if the there are too many messages for the given queue. To enable this on a pool, you need to define the `backed_up_alert_threshold` config value.

```yaml
# ...
    sales:
      backed_up_alert_threshold: 100
```

If there are over 100 messages in the sales pool, then the PoolBackedUpAlert will fire on the next check.

### Creating Your Own Alerts

To create an alert, you need to subscribe to the RaiseAlerts interface, then register that service, and if you enable auto configuration, it should automatically get tagged with `messenger_auto_scale.raise_alerts`.

## Accessing Supervisor Pool Config from Symfony App

When installing this as a bundle in a symfony app, it can be helpful to provide access to some internal config structures. The library exposes services which can be injected/accessed to provide access to the internal config.

### Supervisor Pool Config Array

`krak.messenger_auto_scale.supervisor_pool_configs` stores `list<SupervisorPoolConfig>` based off of the auto scale config.

### Receiver To Pool Names Array

`krak.messenger_auto_scale.receiver_to_pool_mapping` stores `array<string, string>` which maps the messenger reciever ids to the auto scale pool names.

## Testing

You can run the test suite with: `composer test`

You'll need to start the redis docker container locally in order for the Feature test suite to pass.

Keep in mind that you will need to have the redis-ext installed on your local php cli, and will need to start up the redis instance in docker via `docker-compose`.
