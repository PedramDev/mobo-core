<?php

namespace MoboCore;

class ApiFunctions
{
    private $base_url = 'https://customers.mobomobo.ir/';
    // private $base_url = 'https://localhost:7015/';

    function fetch_data_from_api($url)
    {
        $token = get_option('mobo_core_token');
        // Set up the headers
        $args = [
            'headers' => [
                'Token' => $token,
                'timeout'     => 20
            ],
        ];

        // Make a GET request
        $response = \wp_remote_get($url, $args);

        // Check for errors
        if (\is_wp_error($response)) {

            trace_log('error in response:get-products-count :');
            trace_log($url);
            trace_log(print_r($response));
            trace_log(print_r($args));
            return false;
        }

        // Get the response body
        $body = \wp_remote_retrieve_body($response);

        // Decode the JSON into an associative array
        $data = json_decode($body, true);

        if ($data === null) {
            return false;
        }

        return $data; // Return the data or process it as needed
    }

    public function getProductsCount($onlyInStock)
    {
        $token = get_option('mobo_core_token');

        // Set up the headers
        $args = [
            'headers' => [
                'Token' => $token,
                'timeout'     => 20
            ],
        ];

        $onlyInStock = $onlyInStock == '1' ? 'true' : null;

        // Make a GET request
        $response = \wp_remote_get($this->base_url . "get-products-count?onlyInStock=$onlyInStock", $args);

        // Check for errors
        if (is_wp_error($response)) {
            trace_log('error in response:get-products-count :');
            trace_log(print_r($response));
            return false;
        }

        // Get the response body
        $body = \wp_remote_retrieve_body($response);

        return $body;
    }

    public function getImageByGuid($img_guid)
    {
        $token = get_option('mobo_core_token');
        // Set up the headers
        $args = [
            'headers' => [
                'Token' => $token,
                'timeout'     => 20
            ],
        ];

        $url = $this->base_url . "image-url/$img_guid";

        // Make a GET request
        $response = \wp_remote_get($url, $args);

        // Check for errors
        if (\is_wp_error($response)) {

            trace_log('error in response:get-products-count :');
            trace_log($url);
            trace_log(print_r($response));
            trace_log(print_r($args));
            return false;
        }

        $body = \wp_remote_retrieve_body($response);

        return $body;
    }

    public function getProductsAsJson($pageNumber, $recordPerPage, $onlyInStock)
    {
        $onlyInStock = $onlyInStock == '1' ? 'true' : null;

        $productsArray = $this->fetch_data_from_api($this->base_url . "get-products?PageNumber=$pageNumber&RecordPerPage=$recordPerPage&onlyInStock=$onlyInStock");
        // $productsArray = $this->fetch_data_from_api($this->base_url . "get-products-by-portal-id?ProductPortalId=172122254");
        return $productsArray;
    }

    
    public function getProductByGuidAsJson($productGuid)
    {
        $productsArray = $this->fetch_data_from_api($this->base_url . "get-products-by-guid?ProductId=$productGuid");
        return $productsArray;
    }

    public function getCategoriesAsJson()
    {
        $categoriesArray = $this->fetch_data_from_api($this->base_url . 'get-categories');
        return $categoriesArray;
    }


    public function getLicenseInfo()
    {
        $info = $this->fetch_data_from_api($this->base_url . 'LicenseInfo');
        return $info;
    }
}
