# DnD Atmosphere Generator

Speak a scene description in Any Language you like → it gets transcribed → a atmospheric image is generated.
Built for DnD sessions: the DM generates images, players see them live on their own devices.

## Requirements

- Docker + Docker Compose
- OpenAI API key
- ~500 MB–3 GB disk space for the Whisper model (see below)

## Setup

**1. Configure your API key**

```bash
cp .env.example .env
# Edit .env and paste your OpenAI API key
```

**2. Download a Whisper model**

Whisper converts your spoken Czech into text. Pick a model based on your hardware:

| Model | Size | Czech quality | Transcription time (CPU) |
|-------|------|---------------|--------------------------|
| `tiny` | 75 MB | poor | ~5s |
| `base` | 142 MB | mediocre | ~10s |
| `small` | 466 MB | decent | ~30s |
| `medium` | 1.4 GB | **good** ← recommended | ~90s |
| `large` | 2.9 GB | best | ~4 min |

```bash
./dnd-atmo model medium
```

**3. Start**

```bash
./dnd-atmo run
```

First start takes several minutes (compiles whisper.cpp from source). Subsequent starts are instant.

## DM Interface — `localhost:8080`

Open this on the machine running the app.

1. Set the **Base Style Prompt** to define the visual style for your session
2. Click **Start Listen** and describe the current scene in Czech
3. Click **Do The Magic** to submit — or **I Changed My Mind** to cancel
4. The transcription appears while the image generates so you can verify what was heard
5. The image fades in; all session images are kept in the gallery strip below

**Gallery management:** hover any thumbnail to reveal a `×` delete button.
Delete is only available from `localhost` — players cannot delete images.

**QR codes:** a "Show player QR codes" toggle appears below the title once the app is running.
Scan one to open the player viewer on any device on the same network.

There is a 30-second cooldown between generations.

## Player Viewer — `http://<host-ip>:8080/viewer.html`

A minimal read-only page for players. Shows the current image and gallery.
Automatically updates when a new image is generated — no refresh needed.
Clicking a gallery thumbnail shows that image and its prompt.

Use the QR codes on the DM screen to navigate players to the correct URL.

## Commands

```bash
./dnd-atmo run           # check setup and start
./dnd-atmo stop          # stop containers
./dnd-atmo restart       # rebuild and restart (picks up code changes)
./dnd-atmo logs          # tail container logs
./dnd-atmo model medium  # download/replace Whisper model (tiny/base/small/medium/large)
```

## Cost

Images use DALL-E 3 (~$0.04 per image). Session and lifetime totals are shown in the DM
interface and persisted in `data/costs.json`.

## Data

```
public/sessions/YYYY-MM-DD/image_NN.png   — generated images
public/sessions/YYYY-MM-DD/image_NN.txt   — prompt used for each image
data/costs.json                           — lifetime and session cost tracking
models/ggml-model.bin                     — whisper model (not in Docker image)
```
