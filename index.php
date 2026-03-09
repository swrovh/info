<?php
$API_KEY = "ASH007";
if(!isset($_GET['key']) || $_GET['key'] !== $API_KEY){
    header("Content-Type: application/json");
    echo json_encode(["status"=>false,"message"=>"Invalid API Key"]);
    exit;
}
?>

// rc_api.php

$AES_KEY = "RTO@N@1V@\$U2024#";
$CUSTOM_RESPONSE_MESSAGE = "Fetched [ SENPAI ]";

$CARS24_CONFIG = [
    'BASE_URL' => 'https://seller-lead.cars24.team',
    'AUTH_HEADER' => 'Basic ',
    'PVT_AUTH_HEADER' => 'Bearer ',
    'PHONE_NUMBER' => '',
    'USER_ID' => ''
];

// PKCS7 padding function
function pkcs7_pad($data, $blockSize) {
    $pad = $blockSize - (strlen($data) % $blockSize);
    return $data . str_repeat(chr($pad), $pad);
}

// AES-128-ECB Encryption matching Node.js implementation
function encrypt($plaintext, $key) {
    // Pad the plaintext to 16-byte boundary
    $plaintext = pkcs7_pad($plaintext, 16);
    
    // Encrypt
    $cipher = "aes-128-ecb";
    $encrypted = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    
    // Return base64 encoded
    return base64_encode($encrypted);
}

// AES-128-ECB Decryption
function decrypt($ciphertextBase64, $key) {
    try {
        $cipher = "aes-128-ecb";
        $decrypted = openssl_decrypt(base64_decode($ciphertextBase64), $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        
        // Remove PKCS7 padding
        $pad = ord($decrypted[strlen($decrypted) - 1]);
        return substr($decrypted, 0, -$pad);
    } catch (Exception $error) {
        error_log("❌ Decryption error: " . $error->getMessage());
        return null;
    }
}

// Fetch unmasked data
function getUnmaskedData($rcNumber) {
    $url = "http://147.93.27.177:3000/rc?search=" . urlencode($rcNumber);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("⚠️ Unmasked API cURL error: " . $error);
        return null;
    }
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['code']) && $data['code'] === "SUCCESS" && isset($data['data'])) {
            return [
                'owner_name' => $data['data']['registration_details']['owner_name'] ?? null,
                'father_name' => $data['data']['ownership_details']['father_name'] ?? null,
                'vehicle_age' => $data['data']['important_dates']['vehicle_age'] ?? null
            ];
        }
    }
    
    error_log("⚠️ Unmasked API failed for RC: " . $rcNumber . " HTTP Code: " . $httpCode);
    return null;
}

// Create lead and get challan info
function getChallanInfo($rcNumber) {
    global $CARS24_CONFIG;
    
    error_log("🚓 Fetching challan info for: " . $rcNumber);
    
    $leadData = [
        'phone' => $CARS24_CONFIG['PHONE_NUMBER'],
        'vehicle_reg_no' => $rcNumber,
        'user_id' => $CARS24_CONFIG['USER_ID'],
        'whatsapp_consent' => true,
        'type' => 'challan',
        'device_category' => 'Mweb'
    ];
    
    // Create lead
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $CARS24_CONFIG['BASE_URL'] . '/prospect/lead');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($leadData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'authority: seller-lead.cars24.team',
        'accept: application/json, text/plain, */*',
        'authorization: ' . $CARS24_CONFIG['AUTH_HEADER'],
        'content-type: application/json',
        'origin: https://www.cars24.com',
        'pvtauthorization: ' . $CARS24_CONFIG['PVT_AUTH_HEADER'],
        'referer: https://www.cars24.com/',
        'user-agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $leadResponse = curl_exec($ch);
    $leadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("⚠️ Challan API cURL error: " . $error);
        return null;
    }
    
    if ($leadHttpCode !== 200) {
        error_log("⚠️ Failed to create lead. HTTP Code: " . $leadHttpCode . " Response: " . $leadResponse);
        return null;
    }
    
    $leadData = json_decode($leadResponse, true);
    if (!$leadData || !isset($leadData['success']) || !$leadData['success']) {
        error_log("⚠️ Failed to create lead: " . ($leadData['message'] ?? 'Unknown error'));
        return null;
    }
    
    $token = $leadData['detail']['token'] ?? null;
    if (!$token) {
        error_log("⚠️ No token received from lead creation");
        return null;
    }
    
    error_log("✅ Lead created successfully, token: " . $token);
    
    // Get challan list
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $CARS24_CONFIG['BASE_URL'] . '/challan/list/' . $token);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'authority: seller-lead.cars24.team',
        'accept: application/json, text/plain, */*',
        'authorization: ' . $CARS24_CONFIG['AUTH_HEADER'],
        'device_category: m-web',
        'origin: https://www.cars24.com',
        'origin_source: c2b-website',
        'platform: Challan',
        'pvtauthorization: ' . $CARS24_CONFIG['PVT_AUTH_HEADER'],
        'referer: https://www.cars24.com/',
        'user-agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $challanResponse = curl_exec($ch);
    $challanHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("⚠️ Challan list API cURL error: " . $error);
        return null;
    }
    
    if ($challanHttpCode === 200 && $challanResponse) {
        $challanData = json_decode($challanResponse, true);
        if (isset($challanData['status']) && $challanData['status'] === 200) {
            error_log("✅ Found challan data for " . $rcNumber);
            return $challanData['detail'] ?? null;
        }
    }
    
    error_log("⚠️ No challan data found or API error. HTTP Code: " . $challanHttpCode . " Response: " . $challanResponse);
    return null;
}

// Process challan data
function processChallanData($challanDetail) {
    if (!$challanDetail) return null;
    
    $processed = [
        'processing_status' => $challanDetail['processingStatus'] ?? null,
        'total_online_amount' => $challanDetail['pendingChallans']['totalOnlineChallanAmount'] ?? 0,
        'total_offline_amount' => $challanDetail['pendingChallans']['totalOfflineChallanAmount'] ?? 0,
        'total_amount' => ($challanDetail['pendingChallans']['totalOnlineChallanAmount'] ?? 0) + 
                         ($challanDetail['pendingChallans']['totalOfflineChallanAmount'] ?? 0),
        'pending_challans' => []
    ];
    
    // Process physical court challans
    if (isset($challanDetail['pendingChallans']['physicalCourtChallans'])) {
        foreach ($challanDetail['pendingChallans']['physicalCourtChallans'] as $challan) {
            $processed['pending_challans'][] = [
                'challan_no' => $challan['challanNo'] ?? null,
                'unique_id' => $challan['uniqueIdentifier'] ?? null,
                'status' => $challan['status'] ?? null,
                'computed_status' => $challan['computedStatus'] ?? null,
                'offence_name' => $challan['offences'][0]['offenceName'] ?? "Unknown Offence",
                'penalty_amount' => $challan['amount'] ?? 0,
                'date_time' => $challan['dateTime'] ?? null,
                'location' => $challan['offenceLocation'] ?? null,
                'state' => $challan['stateCd'] ?? null,
                'court_type' => $challan['courtType'] ?? null,
                'payment_status' => $challan['paymentStatus'] ?? null,
                'pending_duration' => $challan['challanPendingFor'] ?? null,
                'is_payable' => $challan['isPayable'] ?? false,
                'challan_images' => $challan['challanImages'] ?? [],
                'provider_type' => $challan['challanProviderSubType'] ?? null
            ];
        }
    }
    
    // Process virtual court challans
    if (isset($challanDetail['pendingChallans']['virtualCourtChallans'])) {
        foreach ($challanDetail['pendingChallans']['virtualCourtChallans'] as $challan) {
            $processed['pending_challans'][] = [
                'challan_no' => $challan['challanNo'] ?? null,
                'unique_id' => $challan['uniqueIdentifier'] ?? null,
                'status' => $challan['status'] ?? null,
                'computed_status' => $challan['computedStatus'] ?? null,
                'offence_name' => $challan['offences'][0]['offenceName'] ?? "Unknown Offence",
                'penalty_amount' => $challan['amount'] ?? 0,
                'date_time' => $challan['dateTime'] ?? null,
                'location' => $challan['offenceLocation'] ?? null,
                'state' => $challan['stateCd'] ?? null,
                'court_type' => $challan['courtType'] ?? null,
                'payment_status' => $challan['paymentStatus'] ?? null,
                'pending_duration' => $challan['challanPendingFor'] ?? null,
                'is_payable' => $challan['isPayable'] ?? false,
                'challan_images' => $challan['challanImages'] ?? [],
                'provider_type' => $challan['challanProviderSubType'] ?? null
            ];
        }
    }
    
    // Process recently added challans
    if (isset($challanDetail['pendingChallans']['recentlyAddedChallans'])) {
        foreach ($challanDetail['pendingChallans']['recentlyAddedChallans'] as $challan) {
            $processed['pending_challans'][] = [
                'challan_no' => $challan['challanNo'] ?? null,
                'unique_id' => $challan['uniqueIdentifier'] ?? null,
                'status' => $challan['status'] ?? null,
                'computed_status' => $challan['computedStatus'] ?? null,
                'offence_name' => $challan['offences'][0]['offenceName'] ?? "Unknown Offence",
                'penalty_amount' => $challan['amount'] ?? 0,
                'date_time' => $challan['dateTime'] ?? null,
                'location' => $challan['offenceLocation'] ?? null,
                'state' => $challan['stateCd'] ?? null,
                'court_type' => $challan['courtType'] ?? null,
                'payment_status' => $challan['paymentStatus'] ?? null,
                'pending_duration' => $challan['challanPendingFor'] ?? null,
                'is_payable' => $challan['isPayable'] ?? false,
                'challan_images' => $challan['challanImages'] ?? [],
                'provider_type' => $challan['challanProviderSubType'] ?? null
            ];
        }
    }
    
    // Sort by date (newest first)
    usort($processed['pending_challans'], function($a, $b) {
        return strtotime($b['date_time']) - strtotime($a['date_time']);
    });
    
    return $processed;
}

// Format challan response
function formatChallanResponse($processedChallanInfo) {
    if (!$processedChallanInfo) {
        return [
            'status' => false,
            'response_code' => 404,
            'response_message' => "No challan information available",
            'data' => []
        ];
    }
    
    return [
        'status' => true,
        'response_code' => 200,
        'response_message' => "Challan information fetched successfully",
        'data' => [$processedChallanInfo]
    ];
}

// Merge RC data with unmasked data
function mergeRcData($originalData, $unmaskedData) {
    global $CUSTOM_RESPONSE_MESSAGE;
    
    if (!isset($originalData['data']) || !is_array($originalData['data']) || count($originalData['data']) === 0) {
        return $originalData;
    }
    
    $mergedData = $originalData;
    $rcItem = $mergedData['data'][0];
    
    if ($unmaskedData) {
        if (isset($unmaskedData['owner_name']) && isset($rcItem['owner_name']) && strpos($rcItem['owner_name'], '*') !== false) {
            error_log("🔄 Replacing owner_name: " . $rcItem['owner_name'] . " → " . $unmaskedData['owner_name']);
            $rcItem['owner_name'] = $unmaskedData['owner_name'];
        }
        
        if (isset($unmaskedData['father_name']) && isset($rcItem['father_name']) && strpos($rcItem['father_name'], '*') !== false) {
            error_log("🔄 Replacing father_name: " . $rcItem['father_name'] . " → " . $unmaskedData['father_name']);
            $rcItem['father_name'] = $unmaskedData['father_name'];
        }
        
        if (isset($unmaskedData['vehicle_age'])) {
            error_log("🔄 Adding vehicle_age: " . $unmaskedData['vehicle_age']);
            $rcItem['vehicle_age'] = $unmaskedData['vehicle_age'];
        } elseif (!isset($rcItem['vehicle_age'])) {
            error_log("ℹ️ No vehicle_age available from unmasked API");
        }
    }
    
    $mergedData['response_message'] = $CUSTOM_RESPONSE_MESSAGE;
    error_log("✅ Response message set to: \"" . $CUSTOM_RESPONSE_MESSAGE . "\"");
    
    $mergedData['data'] = [$rcItem];
    return $mergedData;
}

// Decrypt API response
function decryptApiResponse($encryptedResponse) {
    global $AES_KEY;
    
    try {
        if (is_string($encryptedResponse)) {
            $decrypted = decrypt($encryptedResponse, $AES_KEY);
            if ($decrypted) {
                // Remove any trailing null characters
                $decrypted = rtrim($decrypted, "\0");
                
                $json = json_decode($decrypted, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $json;
                } else {
                    error_log("⚠️ Decrypted data is not JSON: " . $decrypted);
                    return $decrypted;
                }
            }
        }
        
        return $encryptedResponse;
    } catch (Exception $error) {
        error_log("❌ Response decryption error: " . $error->getMessage());
        return $encryptedResponse;
    }
}

// Make request to main RC API
function getRcDetailsFromApi($encryptedRc) {
    // Build multipart form data like Node.js FormData
    $boundary = '----WebKitFormBoundary' . md5(microtime());
    
    $formFields = [
        'YLnoBJXFHWIb6n+vaU5Fqw===' => 'hEetH/fxDYkaiPV1O08JXGavuWKAHB7H//KqlbPQizq1sxbHamO8edqhIcOJJybWVc4wf11tUxC1uEtwt2OHiKuzQ4fSmex9pkrf6bj/yztMQT9yb5+E3V3RttX0S1WRXRiNakRvo+pOiu6k8j8M+C6aLHvrWxqTQnP9ND0xv3EQyxcgjYt5rk2qVOWP+nf8',
        'uniDRnuJvTpCyd8qqa7bmg===' => '6UcabyegT3XEmP2Mw0Jwfw==',
        'wmbVbuTELPkity3gk1FSLw===' => 'hwc6sd9eQz3sd8aZ5tWtOSO9P/8c0ruHIRUDVqC4PzmK3ZgUJ5W/1ibrOgk6+bHhGaWCca3iQ6qfy5v/zhdLXw==',
        'kqvOc7zzeKL9GQi3s97hRg===' => 'KOgloc/Wkh/JKFVr/Y5bZA==',
        '6itFonmUeG7GaEL8YAz1dw===' => 'DHKgKTb0PD667WXK14bQxQ==',
        'gaQw08ye60GZvOaEjDxwSg===' => '7Xx2UpV+mliqWirrrkrJ4A==',
        'KldjgNJiCoLPelKQK12wCg===' => 'Wg4luew+ZNYaVLvuYevUwhJMt5Q0FwINOnT3ntNuXiM=',
        '8qv0XiLt71c2Mcb7A/0ETw===' => '2femjV0XNiZlRIoza3rq/Q==',
        'zKMffadDKn74L6D8Erq/Ow===' => 'HjCiWD0aGnOHqRk+sJhmSg==',
        'aQ1IgwRQsEsftk0pG3qVOA===' => 'NDEpmB1IH3r0ZWPKlDX42g==',
        'kxBCVJqsDl1CnYYrPI+ESg===' => '6UcabyegT3XEmP2Mw0Jwfw==',
        '4svShi1T5ftaZPNNHhJzig===' => $encryptedRc,
        'lES0BMK4Gbc62W3W5/cR3Q===' => '6UcabyegT3XEmP2Mw0Jwfw==',
        '5ES5V9fBsVv2zixvup+QfGUYTXf6w2Wb7rfo1vbyiZo==' => '6UcabyegT3XEmP2Mw0Jwfw==',
        'w0dcvRNvk81864M2TM1R4w===' => '4n04akOAWVJ7qY7ccwxckA==',
        'Qh35ea+zP5C5YndUy+/5hQ===' => 'Eky3lDQXAg06dPee025eIw==',
        'zdR9T9RDHgdRB7xdozvLRNUdr4dDNKvva1aeDyqC22ASTLeUNBcCDTp0957Tbl4j=' => 'zeLxdIWt2S3VdsxhpTwY1A==',
        'eMY6P1CkF0Iya2o8nxqYGpW47fJY0qkIn/5knbV9Kos==' => 'zeLxdIWt2S3VdsxhpTwY1A=='
    ];
    
    // Build multipart form data
    $data = '';
    foreach ($formFields as $name => $value) {
        $data .= "--{$boundary}\r\n";
        $data .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
        $data .= "{$value}\r\n";
    }
    $data .= "--{$boundary}--\r\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://rcdetailsapi.vehicleinfo.app/api/vasu_rc_doc_details");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: okhttp/5.0.0-alpha.11',
        'Accept-Encoding: gzip',
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'authorization: ',
        'version_code: 13.39',
        'device_type: android'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL error: " . $error);
    }
    
    if ($httpCode !== 200) {
        throw new Exception("Failed to fetch RC details. HTTP Code: " . $httpCode . " Response: " . $response);
    }
    
    return $response;
}

// Main API endpoint handler
function handleRcRequest($rc) {
    global $AES_KEY;
    
    if (!$rc) {
        return [
            'status' => false,
            'message' => "Missing query parameter. Example: /rc_api.php?rc=JH05DE7988",
        ];
    }
    
    try {
        // Fetch unmasked data and challan info
        $unmaskedData = getUnmaskedData($rc);
        $challanDetail = getChallanInfo($rc);
        
        $processedChallanInfo = processChallanData($challanDetail);
        
        // Encrypt RC
        $encryptedRc = encrypt($rc, $AES_KEY);
        error_log("🔐 Encrypted RC: " . $encryptedRc . " for RC: " . $rc);
        
        // Get RC details from main API
        $apiResponse = getRcDetailsFromApi($encryptedRc);
        
        // Decrypt the response
        $rc_xhudai = decryptApiResponse($apiResponse);
        error_log("🔓 Decrypted response type: " . gettype($rc_xhudai));
        
        if (is_string($rc_xhudai)) {
            error_log("🔓 Decrypted response (string): " . $rc_xhudai);
        }
        
        // If response is JSON array, merge with unmasked data
        if ($rc_xhudai && is_array($rc_xhudai)) {
            $rc_xhudai = mergeRcData($rc_xhudai, $unmaskedData);
        }
        
        return [
            'query' => $rc,
            'rc_chudai' => $rc_xhudai,
            'challan_info' => formatChallanResponse($processedChallanInfo)
        ];
        
    } catch (Exception $error) {
        error_log("❌ Request error: " . $error->getMessage());
        return [
            'status' => false,
            'message' => "Failed to fetch RC details",
            'error' => $error->getMessage(),
        ];
    }
}

// Handle the request
header('Content-Type: application/json');

// Get RC from query parameter
$rc = isset($_GET['rc']) ? trim($_GET['rc']) : null;
$rc = isset($_GET['query']) ? trim($_GET['query']) : $rc;

// Handle the request and output JSON
$response = handleRcRequest($rc);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>