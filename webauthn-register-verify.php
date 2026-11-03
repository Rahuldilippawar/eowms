<?php
// Fingerprint / Face ID (WebAuthn) सुविधा काढून टाकली आहे — Login व पत्र-Sign
// आता फक्त Password ने. हा Endpoint जुन्या Links/Apps साठी फक्त स्पष्ट उत्तर देतो.
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode(['success' => false, 'message' => 'Fingerprint / Face ID सुविधा बंद केली आहे — कृपया Password वापरा.'], JSON_UNESCAPED_UNICODE);
