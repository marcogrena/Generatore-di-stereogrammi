from __future__ import annotations

import io
import math
import os
from datetime import datetime

from flask import Flask, flash, redirect, render_template, request, send_file, send_from_directory, url_for
from PIL import Image
from werkzeug.utils import secure_filename

app = Flask(__name__)
app.secret_key = "stereogram-secret"

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
STEREOGRAM_DIR = os.path.join(BASE_DIR, "stereogrammi")
OBJ_DIR = os.path.join(BASE_DIR, "obj")
BACKGROUND_DIR = os.path.join(BASE_DIR, "background")

ALLOWED_OBJ_EXTENSIONS = {"obj"}
ALLOWED_IMAGE_EXTENSIONS = {"png", "jpg", "jpeg", "webp", "bmp", "jfif"}
ALLOWED_OUTPUT_SIZES = {
    "1200x800": (1200, 800),
    "1280x720": (1280, 720),
    "1920x1080": (1920, 1080),
    "2560x1440": (2560, 1440),
}


def _recent_stereograms(limit: int = 12) -> list[dict[str, str]]:
    if not os.path.isdir(STEREOGRAM_DIR):
        return []

    items: list[dict[str, str | float]] = []
    for name in os.listdir(STEREOGRAM_DIR):
        lower = name.lower()
        if not (lower.endswith(".jpg") or lower.endswith(".jpeg")):
            continue

        path = os.path.join(STEREOGRAM_DIR, name)
        if not os.path.isfile(path):
            continue

        modified = datetime.fromtimestamp(os.path.getmtime(path)).strftime("%d/%m/%Y %H:%M")
        modified_epoch = os.path.getmtime(path)
        items.append(
            {
                "name": name,
                "url": url_for("stereogram_file", filename=name),
                "modified": modified,
                "modified_epoch": modified_epoch,
            }
        )

    items.sort(key=lambda item: float(item["modified_epoch"]), reverse=True)

    final_items: list[dict[str, str]] = []
    for item in items[:limit]:
        final_items.append(
            {
                "name": str(item["name"]),
                "url": str(item["url"]),
                "modified": str(item["modified"]),
            }
        )

    return final_items


def _available_files(directory: str, allowed_extensions: set[str]) -> list[str]:
    if not os.path.isdir(directory):
        return []

    items: list[str] = []
    for name in os.listdir(directory):
        path = os.path.join(directory, name)
        if not os.path.isfile(path):
            continue
        if not _allowed_file(name, allowed_extensions):
            continue
        items.append(name)

    items.sort()
    return items


def _resolve_existing_file(directory: str, filename: str, allowed_extensions: set[str]) -> str:
    cleaned = (filename or "").strip()
    if not cleaned:
        raise ValueError("Nome file non valido.")

    if os.path.basename(cleaned) != cleaned:
        raise ValueError("Percorso file non valido.")

    if not _allowed_file(cleaned, allowed_extensions):
        raise ValueError("Estensione file non supportata.")

    available = set(_available_files(directory, allowed_extensions))
    if cleaned not in available:
        raise ValueError("File non trovato nella cartella prevista.")

    return os.path.join(directory, cleaned)


def _allowed_file(filename: str, allowed_extensions: set[str]) -> bool:
    if "." not in filename:
        return False
    return filename.rsplit(".", 1)[1].lower() in allowed_extensions


def _clamp(value: float, min_value: float, max_value: float) -> float:
    return max(min_value, min(max_value, value))


def _parse_obj(file_bytes: bytes) -> tuple[list[tuple[float, float, float]], list[tuple[int, int, int]]]:
    text = file_bytes.decode("utf-8", errors="ignore")
    vertices: list[tuple[float, float, float]] = []
    faces: list[tuple[int, int, int]] = []

    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue

        parts = line.split()
        if parts[0] == "v" and len(parts) >= 4:
            try:
                vertices.append((float(parts[1]), float(parts[2]), float(parts[3])))
            except ValueError:
                continue
        elif parts[0] == "f" and len(parts) >= 4:
            indices: list[int] = []
            for token in parts[1:]:
                idx_str = token.split("/")[0]
                if not idx_str:
                    continue
                try:
                    idx = int(idx_str)
                except ValueError:
                    continue

                if idx < 0:
                    idx = len(vertices) + idx
                else:
                    idx -= 1

                if 0 <= idx < len(vertices):
                    indices.append(idx)

            # Triangolazione a ventaglio per facce con piu di 3 vertici.
            for i in range(1, len(indices) - 1):
                faces.append((indices[0], indices[i], indices[i + 1]))

    if not vertices or not faces:
        raise ValueError("Il file OBJ non contiene geometria valida.")

    return vertices, faces


def _render_depth_map(
    vertices: list[tuple[float, float, float]],
    faces: list[tuple[int, int, int]],
    width: int,
    height: int,
    obj_scale: float,
    rot_x_deg: float,
    rot_y_deg: float,
    rot_z_deg: float,
    trans_x_pct: float,
    trans_y_pct: float,
) -> list[float]:
    xs = [v[0] for v in vertices]
    ys = [v[1] for v in vertices]
    zs = [v[2] for v in vertices]

    min_x, max_x = min(xs), max(xs)
    min_y, max_y = min(ys), max(ys)
    min_z, max_z = min(zs), max(zs)

    cx = (min_x + max_x) / 2.0
    cy = (min_y + max_y) / 2.0
    cz = (min_z + max_z) / 2.0

    rx = math.radians(rot_x_deg)
    ry = math.radians(rot_y_deg)
    rz = math.radians(rot_z_deg)

    cosx, sinx = math.cos(rx), math.sin(rx)
    cosy, siny = math.cos(ry), math.sin(ry)
    cosz, sinz = math.cos(rz), math.sin(rz)

    rotated_vertices: list[tuple[float, float, float]] = []
    for x, y, z in vertices:
        tx = x - cx
        ty = y - cy
        tz = z - cz

        # Rotazione Y
        x1 = (tx * cosy) - (tz * siny)
        z1 = (tx * siny) + (tz * cosy)

        # Rotazione X
        y2 = (ty * cosx) - (z1 * sinx)
        z2 = (ty * sinx) + (z1 * cosx)

        # Rotazione Z
        x3 = (x1 * cosz) - (y2 * sinz)
        y3 = (x1 * sinz) + (y2 * cosz)

        rotated_vertices.append((x3, y3, z2))

    rxs = [v[0] for v in rotated_vertices]
    rys = [v[1] for v in rotated_vertices]
    rzs = [v[2] for v in rotated_vertices]

    rmin_x, rmax_x = min(rxs), max(rxs)
    rmin_y, rmax_y = min(rys), max(rys)
    rmin_z, rmax_z = min(rzs), max(rzs)

    span_x = max(rmax_x - rmin_x, 1e-6)
    span_y = max(rmax_y - rmin_y, 1e-6)
    span_z = max(rmax_z - rmin_z, 1e-6)

    # Occupa quasi tutto il frame per rendere l'oggetto piu leggibile nello stereogramma.
    base_scale = min((width * 0.92) / span_x, (height * 0.92) / span_y)
    scale = base_scale * obj_scale
    center_x = (width / 2.0) + ((trans_x_pct / 100.0) * width * 0.45)
    center_y = (height / 2.0) - ((trans_y_pct / 100.0) * height * 0.45)

    projected: list[tuple[float, float, float]] = []
    for x, y, z in rotated_vertices:
        sx = (x * scale) + center_x
        sy = center_y - (y * scale)
        sz = (z - rmin_z) / span_z
        projected.append((sx, sy, sz))

    depth = [0.0] * (width * height)
    z_buffer = [-1e9] * (width * height)

    def edge(ax: float, ay: float, bx: float, by: float, px: float, py: float) -> float:
        return (px - ax) * (by - ay) - (py - ay) * (bx - ax)

    for i1, i2, i3 in faces:
        x1, y1, z1 = projected[i1]
        x2, y2, z2 = projected[i2]
        x3, y3, z3 = projected[i3]

        area = edge(x1, y1, x2, y2, x3, y3)
        if abs(area) < 1e-8:
            continue

        min_px = max(0, int(min(x1, x2, x3)))
        max_px = min(width - 1, int(max(x1, x2, x3)) + 1)
        min_py = max(0, int(min(y1, y2, y3)))
        max_py = min(height - 1, int(max(y1, y2, y3)) + 1)

        for py in range(min_py, max_py + 1):
            for px in range(min_px, max_px + 1):
                fx = px + 0.5
                fy = py + 0.5
                w0 = edge(x2, y2, x3, y3, fx, fy)
                w1 = edge(x3, y3, x1, y1, fx, fy)
                w2 = edge(x1, y1, x2, y2, fx, fy)

                if area > 0:
                    inside = w0 >= 0 and w1 >= 0 and w2 >= 0
                else:
                    inside = w0 <= 0 and w1 <= 0 and w2 <= 0

                if not inside:
                    continue

                z = ((w0 * z1) + (w1 * z2) + (w2 * z3)) / area
                idx = py * width + px
                if z > z_buffer[idx]:
                    z_buffer[idx] = z
                    depth[idx] = _clamp(z, 0.0, 1.0)

    return depth


def _prepare_background(
    image_bytes: bytes,
    width: int,
    height: int,
    bg_scale: float,
    bg_mode: str,
) -> Image.Image:
    image = Image.open(io.BytesIO(image_bytes)).convert("RGB")

    if bg_mode == "tile":
        tile_w = max(16, int(image.width * bg_scale))
        tile_h = max(16, int(image.height * bg_scale))
        tile = image.resize((tile_w, tile_h), Image.Resampling.LANCZOS)
        canvas = Image.new("RGB", (width, height))
        for y in range(0, height, tile_h):
            for x in range(0, width, tile_w):
                canvas.paste(tile, (x, y))
        return canvas

    cover_scale = max(width / image.width, height / image.height)
    final_scale = max(0.01, cover_scale * bg_scale)
    scaled_w = max(width, int(image.width * final_scale))
    scaled_h = max(height, int(image.height * final_scale))

    resized = image.resize((scaled_w, scaled_h), Image.Resampling.LANCZOS)
    left = (scaled_w - width) // 2
    top = (scaled_h - height) // 2
    return resized.crop((left, top, left + width, top + height))


def _generate_stereogram(texture: Image.Image, depth_map: list[float], depth_level: int) -> Image.Image:
    width, height = texture.size
    src = texture.load()
    out = Image.new("RGB", (width, height))
    out_px = out.load()

    eye_sep = max(48, int(width * 0.08))
    # Controlla direttamente l'escursione della disparita': piu alto, piu il rilievo e' evidente.
    disparity_span = max(2, int((depth_level / 100.0) * eye_sep * 0.55))

    for y in range(height):
        parent = list(range(width))

        def find(a: int) -> int:
            while parent[a] != a:
                parent[a] = parent[parent[a]]
                a = parent[a]
            return a

        def union(a: int, b: int) -> None:
            ra = find(a)
            rb = find(b)
            if ra == rb:
                return
            if ra < rb:
                parent[rb] = ra
            else:
                parent[ra] = rb

        row_offset = (y * 31) % max(1, eye_sep)
        base_strip: list[tuple[int, int, int]] = []
        for x in range(eye_sep):
            # Campionamento pseudo-casuale stabile: spezza le bande e rende la fusione piu naturale.
            h = (x * 1103515245 + (y + 1) * 12345 + row_offset * 977) & 0x7FFFFFFF
            tx = h % width
            ty = ((h >> 9) + y * 3) % height
            base_strip.append(src[tx, ty])

        for x in range(width):
            depth = depth_map[(y * width) + x]
            z = depth ** 1.8
            separation = eye_sep - int(disparity_span * z)
            separation = max(1, min(eye_sep, separation))
            left = x - (separation // 2)
            right = left + separation

            if 0 <= left < width and 0 <= right < width:
                union(left, right)

        row_colors: list[tuple[int, int, int] | None] = [None] * width

        for x in range(width):
            root = find(x)
            if root == x:
                row_colors[x] = base_strip[x % eye_sep]
            else:
                row_colors[x] = row_colors[root]

        for x in range(width):
            color = row_colors[x]
            if color is None:
                out_px[x, y] = src[x, y]
            else:
                out_px[x, y] = color

    return out

@app.route("/")
def index():
    return render_template(
        "index.html",
        title="Generatore Stereogrammi",
        output_sizes=ALLOWED_OUTPUT_SIZES,
        gallery_items=_recent_stereograms(),
        available_obj_files=_available_files(OBJ_DIR, ALLOWED_OBJ_EXTENSIONS),
        available_bg_files=_available_files(BACKGROUND_DIR, ALLOWED_IMAGE_EXTENSIONS),
    )


@app.route("/stereogrammi/<path:filename>")
def stereogram_file(filename: str):
    safe_name = secure_filename(filename)
    if not safe_name:
        return redirect(url_for("index"))
    return send_from_directory(STEREOGRAM_DIR, safe_name)


@app.route("/obj/<path:filename>")
def obj_file(filename: str):
    safe_name = os.path.basename(filename)
    if not safe_name or safe_name not in _available_files(OBJ_DIR, ALLOWED_OBJ_EXTENSIONS):
        return redirect(url_for("index"))
    return send_from_directory(OBJ_DIR, safe_name)


@app.route("/background/<path:filename>")
def background_file(filename: str):
    safe_name = os.path.basename(filename)
    if not safe_name or safe_name not in _available_files(BACKGROUND_DIR, ALLOWED_IMAGE_EXTENSIONS):
        return redirect(url_for("index"))
    return send_from_directory(BACKGROUND_DIR, safe_name)


@app.post("/genera-stereogramma")
def genera_stereogramma():
    obj_file = request.files.get("obj_file")
    bg_file = request.files.get("bg_file")
    obj_existing = request.form.get("obj_existing", "")
    bg_existing = request.form.get("bg_existing", "")

    try:
        obj_scale = _clamp(float(request.form.get("obj_scale", "1.0")), 0.2, 8.0)
        bg_scale = _clamp(float(request.form.get("bg_scale", "1.0")), 0.2, 3.0)
        depth_level = int(_clamp(float(request.form.get("depth_level", "35")), 1, 100))
        rot_x = _clamp(float(request.form.get("rot_x", "0")), -180.0, 180.0)
        rot_y = _clamp(float(request.form.get("rot_y", "0")), -180.0, 180.0)
        rot_z = _clamp(float(request.form.get("rot_z", "0")), -180.0, 180.0)
        trans_x = _clamp(float(request.form.get("trans_x", "0")), -100.0, 100.0)
        trans_y = _clamp(float(request.form.get("trans_y", "0")), -100.0, 100.0)
    except ValueError:
        flash("Valori numerici non validi.")
        return redirect(url_for("index"))

    bg_mode = request.form.get("bg_mode", "full")
    if bg_mode not in {"full", "tile"}:
        bg_mode = "full"

    output_size = request.form.get("output_size", "1200x800")
    output_width, output_height = ALLOWED_OUTPUT_SIZES.get(output_size, ALLOWED_OUTPUT_SIZES["1200x800"])

    try:
        if obj_existing:
            obj_path = _resolve_existing_file(OBJ_DIR, obj_existing, ALLOWED_OBJ_EXTENSIONS)
            with open(obj_path, "rb") as f:
                obj_bytes = f.read()
            obj_source_name = os.path.basename(obj_path)
        else:
            if not obj_file or not obj_file.filename:
                raise ValueError("Seleziona un file OBJ oppure scegli un OBJ dalla cartella server.")
            if not _allowed_file(obj_file.filename, ALLOWED_OBJ_EXTENSIONS):
                raise ValueError("Formato OBJ non valido.")
            obj_bytes = obj_file.read()
            obj_source_name = obj_file.filename

        if bg_existing:
            bg_path = _resolve_existing_file(BACKGROUND_DIR, bg_existing, ALLOWED_IMAGE_EXTENSIONS)
            with open(bg_path, "rb") as f:
                bg_bytes = f.read()
        else:
            if not bg_file or not bg_file.filename:
                raise ValueError("Seleziona un'immagine di sfondo oppure scegline una dalla cartella server.")
            if not _allowed_file(bg_file.filename, ALLOWED_IMAGE_EXTENSIONS):
                raise ValueError("Formato immagine non supportato.")
            bg_bytes = bg_file.read()

        vertices, faces = _parse_obj(obj_bytes)
        depth_map = _render_depth_map(
            vertices,
            faces,
            output_width,
            output_height,
            obj_scale,
            rot_x,
            rot_y,
            rot_z,
            trans_x,
            trans_y,
        )
        texture = _prepare_background(bg_bytes, output_width, output_height, bg_scale, bg_mode)
        stereogram = _generate_stereogram(texture, depth_map, depth_level)
    except Exception as exc:
        flash(f"Errore durante la generazione: {exc}")
        return redirect(url_for("index"))

    os.makedirs(STEREOGRAM_DIR, exist_ok=True)

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    obj_name = secure_filename(obj_source_name.rsplit(".", 1)[0]) or "modello"
    out_name = f"stereogramma_{obj_name}_{timestamp}.jpg"
    out_path = os.path.join(STEREOGRAM_DIR, out_name)

    stereogram.save(out_path, format="JPEG", quality=95)

    memory_buffer = io.BytesIO()
    stereogram.save(memory_buffer, format="JPEG", quality=95)
    memory_buffer.seek(0)

    return send_file(
        memory_buffer,
        mimetype="image/jpeg",
        as_attachment=True,
        download_name=out_name,
    )


if __name__ == "__main__":
    app.run(debug=True)
