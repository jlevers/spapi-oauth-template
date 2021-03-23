<?php

require_once __DIR__ . "/../vendor/autoload.php";

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {
    $DEBUG = $_ENV["DEBUG"] === "true";

    /*
     * Display the Authorize page when users GET /
     */
    $app->get("/", function(Request $request, Response $response, $args): Response {
        return $this->get("view")->render($response, "authorize.html");
    });

    /*
     * Redirect to the Amazon OAuth application authorization page when users
     * submit the authorization form
     */
    $app->post("/", function(Request $request, Response $response, $args) use ($DEBUG): Response {
        session_start();
        $state = bin2hex(random_bytes(256));
        $_SESSION["spapi_auth_state"] = $state;
        $_SESSION["spapi_auth_time"] = time();

        // Generate Amazon authorization page URL
        $oauthUrl = $_ENV["SP_OAUTH_ENDPOINT"];
        $oauthPath = "/apps/authorize/consent";
        $oauthQueryParams = [
            "application_id" => $_ENV["SPAPI_APP_ID"],
            "state" => $state,
        ];

        // When testing an application that hasn't yet been approved and listed on the Amazon
        // Marketplace Appstore, it's required to pass a version=beta query parameter
        if ($DEBUG) {
            $oauthQueryParams["version"] = "beta";
        }

        $uri = new Uri($oauthUrl);
        $uri = $uri->withScheme("https")
                   ->withPath($oauthPath);
        $uri = $uri->withQueryValues($uri, $oauthQueryParams);

        $response = $response->withHeader("Referrer-Policy", "no-referrer");
        // Redirect to Amazon's authorization page
        $response = $response->withHeader("Location", strval($uri));
        return $response;
    });

    /*
     * When the user approves the application on Amazon's authorization page, they are redirected
     * to the URL specified in the application config on Seller Central. A number of query parameters
     * are passed, including an LWA (Login with Amazon) token which we can use to fetch the  user's
     * SP API refresh token. With that refresh token, we can generate access tokens that enable us to
     * make SP API requests on the user's behalf.
     */
    $app->get("/redirect", function (Request $request, Response $response, $args): Response {
        $queryString = $request->getUri()->getQuery();
        parse_str($queryString, $queryParams);

        // There are a few places in this route where we need to render a response, so
        // I've abstracted out the render process here
        $outerThis = $this;
        $render = function($params = []) use ($outerThis, $response) {
            return $outerThis->get("view")->render($response, "redirect.html", $params);
        };

        // Check for missing query params
        $missing = [];
        foreach (["state", "spapi_oauth_code", "selling_partner_id"] as $requiredParam) {
            if (!isset($queryParams[$requiredParam])) {
                $missing[] = $requiredParam;
            }
        }
        if (count($missing) > 0) {
            return $render(["err" => "missing", "missing" => $missing]);
        }

        session_start();
        if (!isset($_SESSION)) {
            return $render(["err" => "no_session"]);
        }
        if ($queryParams["state"] !== $_SESSION["spapi_auth_state"]) {
            return $render(["err" => "invalid_state"]);
        }
        // The seller has to authorize the app within 30 minutes of starting the authorization flow
        if (time() - $_SESSION["spapi_auth_time"] > 1800) {
            return $render(["err" => "expired"]);
        }

        [
            "spapi_oauth_code" => $oauthCode,
            "selling_partner_id" => $sellingPartnerId
        ] = $queryParams;

        // Get a refresh token using the OAuth code that Amazon passed us as a query parameter
        $client = new GuzzleHttp\Client();
        $res = null;
        try {
            $res = $client->post("https://api.amazon.com/auth/o2/token", [
                GuzzleHttp\RequestOptions::JSON => [
                    "grant_type" => "authorization_code",
                    "code" => $oauthCode,
                    "client_id" => $_ENV["LWA_CLIENT_ID"],
                    "client_secret" => $_ENV["LWA_CLIENT_SECRET"],
                ]
            ]);
        } catch (GuzzleHttp\Exception\ClientException $e) {
            $info = json_decode($e->getResponse()->getBody()->getContents(), true);
            if ($info["error"] === "invalid_grant") {
                return $render(["err" => "bad_oauth_token"]);
            } else {
                throw $e;
            }
        }

        // Parse out the refresh token (long-lived), the access token (short-lived), and the
        // number of seconds until the access token expires
        $body = json_decode($res->getBody(), true);
        [
            "refresh_token" => $refreshToken,
            "access_token" => $accessToken,
            "expires_in" => $secsTillExpiration,
        ] = $body;

        $config = new SellingPartnerApi\Configuration(["refreshToken" => $refreshToken]);
        $api = new SellingPartnerApi\Api\SellersApi($config);

        $params = $body;
        try {
            $result = $api->getMarketplaceParticipations();
            $params["success"] = true;
        } catch (Exception $e) {
            print_r($e);
        }

        return $render($params);
    });
};
