# DnD Atmosphere Generator

Generates atmospheric images from spoken Czech descriptions during DnD sessions.
You describe a scene out loud → it gets transcribed → an image is generated.

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

Whisper is the speech recognition engine that converts your spoken Czech into text.
The model file is the neural network that does the actual transcription — you choose
the quality/speed trade-off by picking which one to download:

| Model | Size | Czech quality | Transcription time (CPU) |
|-------|------|---------------|--------------------------|
| `tiny` | 75 MB | poor | ~5s |
| `base` | 142 MB | mediocre | ~10s |
| `small` | 466 MB | decent | ~30s |
| `medium` | 1.4 GB | **good** ← recommended | ~90s |
| `large` | 2.9 GB | best | ~4 min |

```bash
dnd-atmo model medium
```

You can re-run this with a different size at any time to swap the model.

> Without a model the app cannot transcribe speech, so the Record button is disabled.
> Image generation from the base prompt alone is intentionally blocked — it would
> produce the same generic image every time and waste API credits.

**3. Start**

```bash
dnd-atmo run
```

The app runs on `http://localhost:8080` and is accessible on your LAN.
First start takes several minutes to build (compiles whisper.cpp from source).
Subsequent starts are instant.

## Usage

1. Edit the **Base Style Prompt** to set the visual style for your session
2. Click **Record** and describe the current scene in Czech
3. Click **Stop** — the app transcribes your speech and generates an image
4. The image fades in; previous images are kept in the gallery strip below

There is a 30-second cooldown between generations.

## Commands

```bash
dnd-atmo run           # check setup and start (detached)
dnd-atmo stop          # stop containers
dnd-atmo logs          # tail container logs
dnd-atmo model medium  # download/replace Whisper model (tiny/base/small/medium/large)
```

## Cost

Images use the OpenAI dall-e-3 API (~$0.04 per image). Session and lifetime totals
are displayed in the app and persisted in `data/costs.json`.
