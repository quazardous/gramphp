<?php

declare(strict_types=1);

namespace Quazardous\GramPHP\Tests\Contract;

use Quazardous\GramPHP\Dag;
use Quazardous\GramPHP\Loop;
use Quazardous\GramPHP\Node;
use Quazardous\GramPHP\Retry;
use Quazardous\GramPHP\Status;

/** The graphs the contract runs on. */
final class Graphs
{
    /** A fork, a join, an optional branch — the diamond. */
    public static function diamond(): Dag
    {
        return new Dag(
            new Node('start', working: 'starting', state: 'started'),
            new Node('left', parents: ['start'], working: 'lefting', state: 'lefted'),
            new Node('right', parents: ['start'], optional: true),
            new Node('end', parents: ['left', 'right']),
        );
    }

    /** Pay and reserve in parallel, ship on both, refund when the payment went through and the reservation failed. */
    public static function saga(): Dag
    {
        return new Dag(
            new Node('order'),
            new Node('pay', parents: ['order']),
            new Node('reserve', parents: ['order']),
            new Node('ship', parents: ['pay', 'reserve']),
            new Node('refund', parents: ['pay', 'reserve'], on: ['reserve' => [Status::Failed]]),
        );
    }

    /** Three engines, two are enough; the merge may be skipped. */
    public static function quorum(): Dag
    {
        return new Dag(
            new Node('scan'),
            new Node('e1', parents: ['scan']),
            new Node('e2', parents: ['scan']),
            new Node('e3', parents: ['scan']),
            new Node('merge', parents: ['e1', 'e2', 'e3'], need: 2, optional: true),
        );
    }

    /** A choice between two routes, a step only one route has, a common end. */
    public static function route(): Dag
    {
        return new Dag(
            new Node('classify', choice: true),
            new Node('publish', parents: ['classify']),
            new Node('reject', parents: ['classify']),
            new Node('notify', parents: ['reject']),
            new Node('end', parents: ['publish', 'notify']),
        );
    }

    /** A draft reviewed up to three times; past that, an escalation. */
    public static function review(): Dag
    {
        return new Dag(
            new Node('draft'),
            new Node('review', parents: ['draft'], loop: new Loop(to: 'draft', max: 2)),
            new Node('publish', parents: ['review']),
            new Node('escalate', parents: ['review'], on: ['review' => [Status::Failed]]),
        );
    }

    /** A call to a flaky service, retried twice with a doubling delay, then an alert. */
    public static function flaky(): Dag
    {
        return new Dag(
            new Node('prepare', lease: '1h'),
            new Node('call', parents: ['prepare'], retry: new Retry(limit: 2, delay: '10s')),
            new Node('alert', parents: ['call'], on: ['call' => [Status::Failed]]),
        );
    }

    /** Send, wait a week for the click, activate or remind; a survey nobody minds being skipped after a day. */
    public static function onboarding(): Dag
    {
        return new Dag(
            new Node('send'),
            new Node('clicked', parents: ['send'], wait: 'email.clicked', timeout: '7d'),
            new Node('activate', parents: ['clicked']),
            new Node('remind', parents: ['clicked'], on: ['clicked' => [Status::Failed]]),
            new Node('survey', parents: ['send'], optional: true, grace: '1d'),
        );
    }
}
