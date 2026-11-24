/*
 * The hamsters on the appraisal pages, and the short wait before a quote
 * while Manager Core fetches prices it did not have yet.
 *
 * Each wait draws one of four scenes at random, never the one shown last, and
 * its line changes every few seconds: the scene's own lines in turn with the
 * shared ones. The hamster beside the paste box is picked the same way, once
 * per page.
 *
 * waitForPrices() always resolves. A wait that fails, is refused or runs
 * long ends with the quote going ahead, priced by the fallback for whatever
 * is still missing, so the seller is never left looking at a spinning wheel.
 */
(function (window, document) {
    'use strict';

    var SCENES = {
        server: {
            work: [
                'Powering up the hamster wheel…',
                'Overclocking the wheel…',
                'Rebooting the hamster. Please hold.',
                'Hot-swapping a tired hamster…',
                'Load balancing across one hamster.',
                '99.9% uptime. The other 0.1% is naps.'
            ],
            out: [
                'All wheels down. Someone unplugged the hamster.'
            ]
        },
        call: {
            work: [
                "Hello, CCP? Yes, we'll hold.",
                'Your call is important to CCP. Please hold.',
                'The hold music is someone reading Jita local out loud.',
                'Press 1 for prices. Press 2 for more hamsters.',
                'Escalating to a senior hamster…',
                'Ticket opened. Priority: snacks.'
            ],
            out: [
                'CCP put us on hold. Forever, apparently.'
            ]
        },
        space: {
            work: [
                'Undocking from Jita 4-4…',
                'Aligning… aligning… still aligning.',
                'Dodging gankers in Uedama…',
                'Warping to zero. Hamsters like it close.',
                'Burning back with your prices…',
                'Docking. Mind the hamster.'
            ],
            out: [
                'Out of fuel. Out of coffee. Out of ideas.'
            ]
        },
        trading: {
            work: [
                'Buy low, sell high, nap later.',
                'Shouting BUY at the wrong people…',
                'Watching the 0.01 ISK war…',
                'Margin Trading: level 0. Confidence: level 5.',
                'The market is up. Or down. Definitely moving.',
                "Reading a chart the hamster drew. It's mostly lines."
            ],
            out: [
                'Market closed. The traders are napping.'
            ]
        }
    };

    var SHARED = {
        work: [
            "Calling CCP… we're on hold.",
            'Reading the order books. Twice.',
            'Haggling with station traders…',
            'Counting the ISK. Recounting the ISK.',
            'Someone undercut us by 0.01 ISK. Again.',
            'Asking Local what Tritanium costs. Local was not helpful.',
            "Pinging Jita… it's lagging. It's always lagging.",
            "Making sure this isn't a Jita scam. Mostly.",
            'Have you tried turning the market off and on again?',
            'It works on my machine.',
            "Blaming DNS. It's always DNS.",
            "Checking the cache. It's in the hamster's cheeks.",
            'Cache miss. Sending a hamster to fetch it.',
            "There are 10 kinds of hamsters: those who get binary and those who don't.",
            'Two hard problems: cache invalidation, naming things and off-by-one errors.',
            'Resolving a merge conflict between two hamsters…',
            'Asking the API nicely. It has opinions.',
            'sudo appraise --please',
            'Running git blame. It was the hamster.',
            'Writing unit tests for the hamster. It is not amused.',
            'Scaling horizontally. Adding more hamsters.',
            "A hamster pushed straight to main. We're looking into it.",
            'Rubber duck debugging, but with a hamster.',
            'Declining all cookies. The hamsters only eat seeds.',
            'Reading the documentation. A first, honestly.',
            'Waiting for the queue worker to finish its coffee…',
            'Deploying on a Friday. Brave hamster.',
            'Turning your loot into JSON and back…',
            'Spreadsheets in space: loading…',
            'Applying the buyback rates…'
        ],
        out: [
            'The hamsters are out of coffee.',
            '404: coffee not found.',
            'hamster.exe has stopped responding.',
            'Kernel panic: not enough caffeine.',
            "Error 418: I'm a teapot. We needed coffee.",
            'We pinged everyone. Nobody pinged back.',
            'Connection timed out. So did the hamsters.'
        ]
    };

    var IDLE = {
        counter: {
            idle: [
                'The counter is open. Paste your loot.',
                'Ding! Oh, nobody. Still waiting…'
            ],
            loot: [
                "Ooh, loot! Hit {button} when you're ready."
            ]
        },
        scales: {
            idle: [
                'The scales are calibrated. Mostly.',
                'We weigh in ISK, not kilograms.'
            ],
            loot: [
                'Weighing it up… Hit {button}.'
            ]
        },
        bay: {
            idle: [
                'Bay 1 is clear. Bring your loot in.',
                'Docking permission granted.'
            ],
            loot: [
                'Cargo received! Hit {button}.'
            ]
        },
        vend: {
            idle: [
                'Insert loot. Receive ISK.',
                'No refunds. Lots of hamsters.'
            ],
            loot: [
                'Loot detected! Hit {button}.'
            ]
        }
    };

    var IDLE_SHARED = {
        idle: [
            "Paste your loot. We'll make an offer.",
            'Ctrl+C in the game, Ctrl+V right here.',
            'Ready when you are.',
            'No loot, no quote. Those are the rules.',
            'The hamsters are warmed up and slightly over-caffeinated.',
            'Inventory, contract or EFT fit: we read them all.',
            "We don't judge your loot. Much.",
            'Idle. Like a freighter on autopilot.',
            'Waiting for input. Any input. Please.',
            'while (!loot) { wait(); }'
        ],
        loot: [
            'That looks valuable. Probably.',
            'Smells like profit.',
            'The hamsters are stretching. Big job incoming.',
            "Hit {button}. The hamsters can't reach the button."
        ]
    };

    var OUT_FIRST = 'The hamsters are out of coffee.';
    var IDLE_SUB = 'Paste in the box: inventory, contract or EFT fit.';

    // Long enough to read a line. A wait is never shorter than one line, so a
    // quote with every price already cached still gets its hamsters.
    var LINE_MS = 3000;
    var OUT_LINE_MS = 4000;
    var MIN_WAIT_MS = 3000;
    var IDLE_LINE_MS = 4500;
    var LOOT_LINE_MS = 3500;
    var FADE_MS = 250;

    function shuffle(list) {
        for (var i = list.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var t = list[i];
            list[i] = list[j];
            list[j] = t;
        }

        return list;
    }

    // Deals every line once, in a fresh order each round, and never the same
    // line twice in a row.
    function deck(list, last) {
        var cards = [];

        return function () {
            if (!cards.length) {
                cards = shuffle(list.slice());
                if (cards.length > 1 && cards[cards.length - 1] === last) {
                    cards.unshift(cards.pop());
                }
            }
            last = cards.pop();

            return last;
        };
    }

    // Shows one of the nodes at random and hides the rest, never the one
    // shown last when there is a choice. Storage can be switched off; the
    // pick is then just random. Showing a scene again restarts its
    // animations from the top.
    function pickOne(nodes, attr, storageKey) {
        if (!nodes.length) {
            return null;
        }
        var last = null;
        try {
            last = window.sessionStorage.getItem(storageKey);
        } catch (e) {
            last = null;
        }
        var choice = [];
        for (var i = 0; i < nodes.length; i++) {
            if (nodes.length === 1 || nodes[i].getAttribute(attr) !== last) {
                choice.push(i);
            }
        }
        var chosen = choice[Math.floor(Math.random() * choice.length)];
        for (var j = 0; j < nodes.length; j++) {
            nodes[j].hidden = j !== chosen;
        }
        var key = nodes[chosen].getAttribute(attr);
        try {
            window.sessionStorage.setItem(storageKey, key);
        } catch (e) {
            // Nothing to keep it in.
        }

        return key;
    }

    // A line that fades out, changes and fades back in.
    function voice(line) {
        var fade = null;

        return {
            say: function (text) {
                window.clearTimeout(fade);
                line.classList.add('is-swapping');
                fade = window.setTimeout(function () {
                    line.textContent = text;
                    line.classList.remove('is-swapping');
                }, FADE_MS);
            },
            set: function (text) {
                this.hush();
                line.textContent = text;
            },
            hush: function () {
                window.clearTimeout(fade);
                line.classList.remove('is-swapping');
            }
        };
    }

    function create(el) {
        var line = voice(el.querySelector('[data-hamster-line]'));
        var sub = el.querySelector('[data-hamster-sub]');
        var scenes = el.querySelectorAll('[data-bb-scene]');
        var timer = null;

        function current() {
            for (var i = 0; i < scenes.length; i++) {
                if (!scenes[i].hidden) {
                    return scenes[i].getAttribute('data-bb-scene');
                }
            }

            return null;
        }

        function pick() {
            return pickOne(scenes, 'data-bb-scene', 'buyback-hamsters-scene');
        }

        function linesFor(key, state) {
            return SCENES[key] ? SCENES[key][state] : [];
        }

        function quiet() {
            window.clearInterval(timer);
            timer = null;
        }

        return {
            start: function () {
                quiet();
                var own = linesFor(pick(), 'work');
                var first = own.length ? own[0] : SHARED.work[0];
                var decks = [deck(SHARED.work, first), deck(own.length ? own : SHARED.work, first)];
                var turn = 0;
                el.classList.remove('bb-hamsters--out');
                el.hidden = false;
                line.set(first);
                sub.textContent = '';
                timer = window.setInterval(function () {
                    line.say(decks[turn++ % 2]());
                }, LINE_MS);
            },
            sub: function (text) {
                sub.textContent = text || '';
            },
            stop: function () {
                quiet();
                line.hush();
                el.hidden = true;
            },
            // Keeps the scene that was working, so the same hamsters run dry.
            outOfCoffee: function (message) {
                quiet();
                var own = linesFor(current() || pick(), 'out');
                var next = own.slice();
                var rest = deck(SHARED.out.concat(own), own.length ? own[own.length - 1] : OUT_FIRST);
                el.hidden = false;
                el.classList.add('bb-hamsters--out');
                line.set(OUT_FIRST);
                sub.textContent = message || '';
                timer = window.setInterval(function () {
                    line.say(next.length ? next.shift() : rest());
                }, OUT_LINE_MS);
            }
        };
    }

    function lootText(count, button) {
        return count + (count === 1 ? ' line' : ' lines') + ' pasted. Press ' + button + " when you're ready.";
    }

    // The hamster beside the paste box: waiting until something is pasted,
    // then excited, and back to waiting when the box is emptied.
    function idle(el) {
        var box = document.getElementById(el.getAttribute('data-hamster-idle'));
        if (!box) {
            return;
        }
        var line = voice(el.querySelector('[data-hamster-line]'));
        var sub = el.querySelector('[data-hamster-sub]');
        var button = el.getAttribute('data-button') || 'Calculate';
        var key = pickOne(el.querySelectorAll('[data-bb-idle]'), 'data-bb-idle', 'buyback-hamsters-idle');
        var own = IDLE[key] || { idle: IDLE_SHARED.idle, loot: IDLE_SHARED.loot };
        var decks = {
            idle: [deck(own.idle, own.idle[0]), deck(IDLE_SHARED.idle, null)],
            loot: [deck(own.loot, own.loot[0]), deck(IDLE_SHARED.loot, null)]
        };
        var mode = null;
        var timer = null;

        function fill(text) {
            return text.replace('{button}', button);
        }

        function run(next) {
            var turn = 1;
            window.clearInterval(timer);
            mode = next;
            el.classList.toggle('bb-idle--loot', next === 'loot');
            line.say(fill(own[next][0]));
            timer = window.setInterval(function () {
                line.say(fill(decks[next][turn++ % 2]()));
            }, next === 'idle' ? IDLE_LINE_MS : LOOT_LINE_MS);
        }

        function update() {
            var count = box.value.split('\n').filter(function (l) { return l.trim() !== ''; }).length;
            var next = count > 0 ? 'loot' : 'idle';
            if (next !== mode) {
                run(next);
            }
            sub.textContent = count > 0 ? lootText(count, button) : IDLE_SUB;
        }

        box.addEventListener('input', update);
        update();
    }

    // A page the server drew out of coffee still gets its lines turning, and
    // every paste box gets its hamster.
    function wake() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-hamsters-out]'), function (el) {
            var sub = el.querySelector('[data-hamster-sub]');
            create(el).outOfCoffee(sub ? sub.textContent : '');
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-hamster-idle]'), idle);
    }

    function waitingText(count) {
        return count > 0
            ? 'Waiting on fresh prices for ' + count + (count === 1 ? ' item…' : ' items…')
            : 'All prices in. Pricing your items…';
    }

    function readJson(response) {
        if (!response.ok) {
            return null;
        }

        return response.json().catch(function () { return null; });
    }

    /*
     * opts.prepareUrl  POST here first, with opts.body and opts.headers
     * opts.waitUrl     function (token) giving the URL to check back on
     * opts.onWaiting   called with the number of items still being fetched,
     *                  and with 0 once they are all in
     */
    function waitForPrices(opts) {
        var began = Date.now();

        return new Promise(function (resolve) {
            var finished = false;
            var safety = null;

            function finish() {
                if (finished) {
                    return;
                }
                finished = true;
                window.clearTimeout(safety);
                window.setTimeout(resolve, Math.max(0, MIN_WAIT_MS - (Date.now() - began)));
            }

            function guard(seconds) {
                window.clearTimeout(safety);
                safety = window.setTimeout(finish, (seconds + 10) * 1000);
            }

            // The hamsters stay up a moment after the last price lands, so
            // the count has to say when nothing is on its way any more.
            function tell(count) {
                if (opts.onWaiting) {
                    opts.onWaiting(count, waitingText(count));
                }
            }

            guard(20);

            window.fetch(opts.prepareUrl, {
                method: 'POST',
                headers: opts.headers || {},
                body: opts.body,
                credentials: 'same-origin'
            }).then(readJson).then(function (data) {
                if (!data) {
                    finish();
                    return;
                }
                if (!data.token || !(data.waiting > 0)) {
                    tell(0);
                    finish();
                    return;
                }

                guard(data.seconds || 20);
                tell(data.waiting);
                var every = Math.max(1, data.poll || 2) * 1000;

                (function later() {
                    window.setTimeout(function () {
                        if (finished) {
                            return;
                        }
                        window.fetch(opts.waitUrl(data.token), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin'
                        }).then(readJson).then(function (state) {
                            if (!state) {
                                finish();
                                return;
                            }
                            if (state.done) {
                                tell(0);
                                finish();
                                return;
                            }
                            tell(state.waiting);
                            later();
                        }).catch(finish);
                    }, every);
                })();
            }).catch(finish);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wake);
    } else {
        wake();
    }

    window.BuybackHamsters = {
        create: create,
        waitForPrices: waitForPrices
    };
})(window, document);
