<?php

define("EOL", "<br>");
set_time_limit(500);

function file_put_json($file, $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT);
    file_put_contents($file, $json);
}

function file_get_json($file, $as_array = false)
{
    return json_decode(file_get_contents($file), $as_array);
}

function file_get_csv($file, $header_row = true)
{
    if (!file_exists($file)) {
        throw new Exception("File not found: " . $file);
    }

    $handle = fopen($file, 'r');
    if ($header_row === true) {
        $header = fgetcsv($handle);
    }

    $array = [];
    while ($row = fgetcsv($handle)) {
        if ($header_row === true) {
            $array[] = array_combine($header, array_map('trim', $row));
        } else {
            $array[] = array_map('trim', $row);
        }
    }
    fclose($handle);
    return $array;
}

function getMessage($data, $productName) {
    if (isset($data[$productName]['message'])) {
        return $data[$productName]['message'];
    }
    return null;
}

function call_sentiment_api($input)
{
    $plainText = strip_tags($input);
    $text = 'text=' . urlencode($plainText);
    $cur_url = "https://text-sentiment-analysis4.p.rapidapi.com/sentiment?".$text;
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $cur_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "x-rapidapi-host: text-sentiment-analysis4.p.rapidapi.com",
            "x-rapidapi-key: a2fa9daeb1mshb860900d90b64c9p191086jsnaf44a68de681"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        throw new Exception("cURL Error #:" . $err);
    }

    return $response;
}

try {
    $csv_data = file_get_csv('dataset.csv');
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . EOL;
    exit();
}

$cache_file = 'cache.json';
$cache_data = file_exists($cache_file) ? file_get_json($cache_file, true) : [];

$cache_names = array_keys($cache_data);
$quota_exceeded_count = 0;
$all_count = 0;

foreach ($csv_data as $csv) {
    $product_name = $csv['name'];
    $all_count++;
    
    
    // echo $product_name . '...';

    if (in_array($product_name, $cache_names)) {
        // echo 'CACHED...' . EOL;
        $message = getMessage($cache_data, $product_name);
        if($message && strpos($message, 'exceeded the MONTHLY quota') !== false){
            $quota_exceeded_count++;
        }
        continue;
    }

    try {
        $description = str_replace('&', ' and ', $csv['description']);
        $response = call_sentiment_api($description);
        if (isset($json['message']) && strpos($json['message'], 'exceeded the MONTHLY quota') !== false) {
            $quota_exceeded_count++;
        }
        $json = json_decode($response, true);
        $json['description'] = $description;
        
        $cache_data[$product_name] = $json;
    } catch (Exception $e) {
        echo "API Error for $product_name: " . $e->getMessage() . EOL;
        continue;
    }

    
}

file_put_json($cache_file, $cache_data);
echo 'SAVE CACHE!' . EOL . EOL;


$highest_pos = 0;
$highest_neg = 0;

$positive_scores = [];
$negative_scores = [];


foreach ($cache_data as $name => $cache) {
    if (!isset($cache['sentiment']) || !isset($cache['sentiment']['score']) || !isset($cache['sentiment']['vote'])) {
        continue;
    }

    $sentiment = $cache['sentiment'];
    $score = $sentiment['score'];
    $vote = $sentiment['vote'];

    if ($vote === 'positive') {
        $positive_scores[$name] = $score;
    } elseif ($vote === 'negative') {
        $negative_scores[$name] = $score;
    }
}

if(count($positive_scores) > 0) {
    arsort($positive_scores);
}
if(count($negative_scores) > 0) {
    asort($negative_scores);
}

$top_5_positive = array_slice($positive_scores, 0, 5, true);
$top_5_negative = array_slice($negative_scores, 0, 5, true);

$proceeded_prod = $all_count-$quota_exceeded_count;

echo "<b>" . $proceeded_prod . " products were processed</b>" . EOL . EOL;



function display_table($title, $data, $cache_data, $is_positive)
{
    echo "<b>$title</b>";
    echo "<table border='1'>";
    echo "<tr><th>Name</th><th>Description</th><th>Score</th><th>Words</th></tr>";

    foreach ($data as $name => $score) {
        $description = $cache_data[$name]['description'];
        if($is_positive)
            $words = implode(", ", $cache_data[$name]['sentiment']['positive']);
        else
            $words = implode(", ", $cache_data[$name]['sentiment']['negative']);

        echo "<tr>";
        echo "<td>$name</td>";
        echo "<td>$description</td>";
        echo "<td>$score</td>";
        echo "<td>$words</td>";
        echo "</tr>" ;
    }

    echo "</table>" . EOL . EOL;
}


display_table("Top 5 Positive Sentiments", $top_5_positive, $cache_data, true);
display_table("Top 5 Negative Sentiments", $top_5_negative, $cache_data, false);


// echo "<b>Top 5 Positive Sentiments:</b>" . EOL;
// foreach ($top_5_positive as $name => $score) {
//     echo "\t$name: $score" . EOL;
// }
// echo EOL;

// echo "<b>Top 5 Negative Sentiments:</b>" . EOL;
// foreach ($top_5_negative as $name => $score) {
//     echo "\t$name: $score" . EOL;
// }
// echo EOL;


if ($quota_exceeded_count > 0) {
    echo "Quota Exceeded Count: " . $quota_exceeded_count . " products were not processed, <b>please buy more api requests</b>" . EOL;
}   