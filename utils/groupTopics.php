<?php
    /**
     * Groups topics by their unit number AND normalises each topic to its
     * two-segment form (unit.subtopic) so it matches what is stored in the DB.
     *
     * The frontend may send granular identifiers such as "4.2.1" or "4.2.2",
     * but the DB stores them as "4.2".  Normalising here — and deduplicating —
     * ensures every DB query uses the correct topic strings.
     *
     * e.g. ["4.2.1", "4.2.2", "4.3.1"] → unit 4 → ["4.2", "4.3"]
     *
     * @param array $topics  Raw topic strings from the request
     * @return array  [ unitNumber => [normalised_topic, …], … ]
     */
    function groupTopicsByUnit(array $topics): array {
        $units = [];
        foreach ($topics as $topic) {
            $parts = explode('.', trim($topic));
            $unit  = (int) $parts[0];
            $normalised = isset($parts[1])
                ? $parts[0] . '.' . $parts[1]
                : $parts[0];
            $units[$unit][] = $normalised;
        }
        foreach ($units as &$topicList) {
            $topicList = array_values(array_unique($topicList));
        }
        return $units;
    }