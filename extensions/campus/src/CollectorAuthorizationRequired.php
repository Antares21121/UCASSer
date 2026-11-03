<?php
namespace UCASSer\Campus;

/** Only an explicit upstream authorization code warrants a replacement QR. */
class CollectorAuthorizationRequired extends \RuntimeException {}
