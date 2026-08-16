<?php

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/validate-ccda-schema.php <document.xml> <CDA_SDTC.xsd>\n");
    exit(2);
}

libxml_use_internal_errors(true);
$document = new DOMDocument;

if (! $document->load($argv[1], LIBXML_NONET)) {
    fwrite(STDERR, "The synthetic C-CDA fixture is not well-formed XML.\n");
    exit(1);
}

if (! $document->schemaValidate($argv[2])) {
    // The gated fixture is synthetic. Keep diagnostics useful without ever printing the
    // document or a path that could be mistaken for a patient-supplied filename.
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, sprintf("CDA schema error at line %d: %s\n", $error->line, trim($error->message)));
    }

    exit(1);
}

fwrite(STDOUT, "CDA R2 core schema: valid\n");
