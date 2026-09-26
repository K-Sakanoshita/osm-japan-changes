#!/usr/bin/env python3

import json
import sys


def calc_bbox(geometry):
    min_lon = float("inf")
    min_lat = float("inf")
    max_lon = float("-inf")
    max_lat = float("-inf")

    def walk(value):
        nonlocal min_lon, min_lat, max_lon, max_lat

        if (
            isinstance(value, list)
            and len(value) >= 2
            and isinstance(value[0], (int, float))
            and isinstance(value[1], (int, float))
        ):
            lon = float(value[0])
            lat = float(value[1])

            min_lon = min(min_lon, lon)
            min_lat = min(min_lat, lat)
            max_lon = max(max_lon, lon)
            max_lat = max(max_lat, lat)
            return

        if isinstance(value, list):
            for child in value:
                walk(child)

    walk(geometry.get("coordinates", []))

    if min_lon == float("inf"):
        return None

    return [
        round(min_lon, 5),
        round(min_lat, 5),
        round(max_lon, 5),
        round(max_lat, 5),
    ]


def main():
    if len(sys.argv) != 3:
        print(
            f"Usage: {sys.argv[0]} input.geojson output.geojson",
            file=sys.stderr,
        )
        sys.exit(1)

    input_path = sys.argv[1]
    output_path = sys.argv[2]

    with open(input_path, encoding="utf-8") as f:
        data = json.load(f)

    for feature in data.get("features", []):
        geometry = feature.get("geometry") or {}
        bbox = calc_bbox(geometry)

        if bbox is not None:
            feature["bbox"] = bbox

    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(
            data,
            f,
            ensure_ascii=False,
            separators=(",", ":"),
        )
        f.write("\n")


if __name__ == "__main__":
    main()
