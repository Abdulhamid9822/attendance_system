<?php

class PosterAuth {

    private $applicationId;
    private $applicationSecret;
    private $redirectUri;

    public function __construct($id, $secret, $redirect) {
        $this->applicationId = $id;
        $this->applicationSecret = $secret;
        $this->redirectUri = $redirect;
    }

    public function getLoginUrl() {
        return "https://joinposter.com/api/auth?" . http_build_query([
            'application_id' => $this->applicationId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code'
        ]);
    }

    public function getAccessToken($code) {

        $url = "https://joinposter.com/api/v2/auth/access_token";

        $postData = [
            'grant_type' => 'authorization_code',
            'application_id' => $this->applicationId,
            'application_secret' => $this->applicationSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

}