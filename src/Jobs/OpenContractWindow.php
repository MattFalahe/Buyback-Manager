<?php

namespace BuybackManager\Jobs;

use BuybackManager\Services\OpenInGame;
use Seat\Eveapi\Jobs\AbstractAuthCharacterJob;
use Seat\Eveapi\Models\RefreshToken;

/**
 * Asks a character's EVE client to show one contract.
 *
 * Run in the request through handle(), never queued: the button answers at
 * once, and a window opening minutes later is no use to anyone. SeAT's own
 * retrieve() refreshes the token and saves it, so Buyback Manager never
 * writes refresh_tokens itself. ESI answers 204 whether or not the client is
 * running, so success only means EVE took the request.
 */
class OpenContractWindow extends AbstractAuthCharacterJob
{
    public $timeout = 30;

    public $tries = 1;

    protected $method = 'post';

    protected $endpoint = '/ui/openwindow/contract/';

    protected $scope = OpenInGame::SCOPE;

    public function __construct(RefreshToken $token, int $contractId)
    {
        parent::__construct($token);

        $this->query_string = ['contract_id' => $contractId];
    }

    /**
     * SeAT's character job handle() reads the queue job, which a call made
     * in the request does not have.
     */
    public function handle()
    {
        $this->retrieve();
    }
}
