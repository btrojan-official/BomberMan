//! This code should be clearly splitted into few other files, but since I had very hard and restricted deadline
//! I'd left this as it is. Maybe I will come back here in the future to make it more clear :)

const wsUri: string = "ws://localhost:46089/server.php";
let websocket: WebSocket;

const canvas: HTMLCanvasElement = document.createElement('canvas');
const ctx: CanvasRenderingContext2D = canvas.getContext('2d')!;
const scalar: number = 1;
const CELL_SIZE: number = 20 * scalar; // Size of each cell in pixels

const WALL_COLORS: Record<number, number> = {
  0: 12*16, // Floor
  1: 48,    // Undestructable wall
  2: 64     // Destructable wall
};

interface Keys {
  up: boolean;
  down: boolean;
  left: boolean;
  right: boolean;
}

const keys: Keys = {
  up: false,
  down: false,
  left: false,
  right: false
};

interface Player {
  x: number;
  y: number;
  speed: number;
}

const player: Player = {
  x: CELL_SIZE * 1.5, // Start at cell (1,1)
  y: CELL_SIZE * 1.5,
  speed: 2 * scalar
};

interface Opponent {
  x: number;
  y: number;
  [key: string]: any; // Allow extra properties if any
}

interface GameAnimation {
  startX: number;
  startY: number;
  endX: number;
  endY: number;
  startTime: number;
  duration: number;
}

let previousOpponents: (Opponent | null)[] = [];
let currentOpponents: Opponent[] = [];
let opponentAnimations: (GameAnimation | null)[] = [];

let lastPongTime = Date.now();

const ANIMATION_DURATION = 1000; // 1 second (was commented 0.5 seconds, so I kept 1s as in original)
const GAME_LOOP_INTERVAL = 10; // 10 times per second
const POSITION_UPDATE_INTERVAL = 100; // Send position updates every 100ms
let lastPositionUpdate: number = 0;

const image = new Image();
image.src = "https://localhost/BomberMan/BomberMan/client/assets/sprites.png";

interface GameData {
  gameField: number[][];
  opponents: Opponent[];
  [key: string]: any;
}

let currentGameData: GameData | null = null;

function init(): void {
  // Set canvas size based on game field dimensions
  canvas.width = 31 * CELL_SIZE;
  canvas.height = 13 * CELL_SIZE;
  document.body.appendChild(canvas);

  // Add keyboard event listeners
  document.addEventListener('keydown', handleKeyDown);
  document.addEventListener('keyup', handleKeyUp);

  // Start game loop
  setInterval(gameLoop, GAME_LOOP_INTERVAL);

  websocket = new WebSocket(wsUri);

  websocket.onopen = (ev: Event) => {
    console.log("open");
  };

  websocket.onmessage = (ev: MessageEvent) => {
    if (ev.data !== "") {
      try {
        const msg = JSON.parse(ev.data);
  
        if (msg.type === "ping") {
          websocket.send(JSON.stringify({ type: "pong" }));
          lastPongTime = Date.now();
        } else if (msg.type === "pong") {
          lastPongTime = Date.now();
        } else if (msg.type === "position_update") {
          // Only update position if player is not currently moving
          if (!keys.up && !keys.down && !keys.left && !keys.right) {
            player.x = msg.position.x;
            player.y = msg.position.y;
          }
        } else if (msg.game) {
          const gameData: GameData = JSON.parse(msg.game);
          updateGameState(gameData);
        } else if (msg.opponents && msg.gameField) {
          const gameData: GameData = msg as GameData;
          updateGameState(gameData);
        }
      } catch (error) {
        console.error("parse error", error, ev.data);
      }
    }
  };
  
  

  websocket.onerror = (ev: Event) => {
    console.log(ev);
  };

  // Add connection monitoring
  lastPongTime = Date.now();
  const connectionCheckInterval = setInterval(() => {
    if (Date.now() - lastPongTime > 10000) { // 10 seconds without pong
      console.log("Connection lost, attempting to reconnect...");
      websocket.close();
      websocket = new WebSocket(wsUri);
      lastPongTime = Date.now();
    }
  }, 5000); // Check every 5 seconds
}

function updateGameState(gameData: GameData): void {
  currentGameData = gameData;
  // Store current opponents as previous
  previousOpponents = [...currentOpponents];
  currentOpponents = gameData.opponents;

  // Update animations for each opponent
  opponentAnimations = currentOpponents.map((opponent, index) => {
    const prevOpponent = previousOpponents[index];
    if (!prevOpponent) return null;

    // Check if position changed
    if (prevOpponent.x !== opponent.x || prevOpponent.y !== opponent.y) {
      return {
        startX: prevOpponent.x,
        startY: prevOpponent.y,
        endX: opponent.x,
        endY: opponent.y,
        startTime: Date.now(),
        duration: ANIMATION_DURATION
      };
    }
    return null;
  });
}

function gameLoop(): void {
  updatePlayerPosition();
  drawGame();
}

function updatePlayerPosition(): void {
  // Calculate movement based on keys pressed
  let dx = 0;
  let dy = 0;

  if (keys.up) dy -= player.speed;
  if (keys.down) dy += player.speed;
  if (keys.left) dx -= player.speed;
  if (keys.right) dx += player.speed;

  // Get the game field and check if it's properly initialized
  const gameField = currentGameData?.gameField;
  if (!gameField || !Array.isArray(gameField) || gameField.length === 0 || !Array.isArray(gameField[0])) {
    // If game field is not properly initialized, don't update position
    return;
  }

  // Calculate potential new position
  const newX = player.x + dx;
  const newY = player.y + dy;

  // Calculate all four corners of the player (19x19 pixels)
  const playerSize = 19;
  const corners = [
    { x: newX - playerSize / 2, y: newY - playerSize / 2 }, // top-left
    { x: newX + playerSize / 2, y: newY - playerSize / 2 }, // top-right
    { x: newX - playerSize / 2, y: newY + playerSize / 2 }, // bottom-left
    { x: newX + playerSize / 2, y: newY + playerSize / 2 }  // bottom-right
  ];

  // Check if all corners are within bounds and on floor tiles
  let canMove = true;
  const invalidCorners: { x: number; y: number }[] = [];
  const validCorners: { x: number; y: number }[] = [];

  for (const corner of corners) {
    const gridX = Math.floor(corner.x / CELL_SIZE);
    const gridY = Math.floor(corner.y / CELL_SIZE);

    if (gridX < 0 || gridX >= gameField[0].length ||
      gridY < 0 || gridY >= gameField.length ||
      gameField[gridY][gridX] !== 0) {
      invalidCorners.push(corner);
    } else {
      validCorners.push(corner);
    }
  }

  // If we have both valid and invalid corners, try to correct the position
  if (invalidCorners.length > 0 && validCorners.length >= 3) {
    // Try moving the invalid corner in different directions
    let corner = invalidCorners[0];
    const maxCorrectionLength = 10;
    let validCorrection: { x: number; y: number } | null = null;

    // Try each direction with increasing correction lengths
    for (let correctionLength = 1; correctionLength <= maxCorrectionLength; correctionLength++) {
      // Only try corrections that aren't opposite to movement direction
      // The original code had:
      // if (dx == 0) corrections.push right and left corrections
      // if (dy == 0) corrections.push up and down corrections
      // But this logic seems inverted: if dx == 0 means no horizontal movement so trying horizontal corrections? 
      // Keeping the same logic for now.

      const corrections: { x: number; y: number }[] = [];
      if (dx === 0) {
        corrections.push({ x: correctionLength, y: 0 }); // right
        corrections.push({ x: -correctionLength, y: 0 }); // left
      }
      if (dy === 0) {
        corrections.push({ x: 0, y: correctionLength }); // down
        corrections.push({ x: 0, y: -correctionLength }); // up
      }

      // Test each correction
      for (const correction of corrections) {
        const testX = newX + correction.x;
        const testY = newY + correction.y;

        // Check if this correction keeps all valid corners valid
        // and makes the invalid corner valid
        let allCornersValid = true;

        // Check invalid corner in new position
        const invalidGridX = Math.floor((corner.x + correction.x) / CELL_SIZE);
        const invalidGridY = Math.floor((corner.y + correction.y) / CELL_SIZE);

        if (invalidGridX < 0 || invalidGridX >= gameField[0].length ||
          invalidGridY < 0 || invalidGridY >= gameField.length ||
          gameField[invalidGridY][invalidGridX] !== 0) {
          continue;
        }

        // Check all valid corners in new position
        for (const validCorner of validCorners) {
          const validGridX = Math.floor((validCorner.x + correction.x) / CELL_SIZE);
          const validGridY = Math.floor((validCorner.y + correction.y) / CELL_SIZE);

          if (validGridX < 0 || validGridX >= gameField[0].length ||
            validGridY < 0 || validGridY >= gameField.length ||
            gameField[validGridY][validGridX] !== 0) {
            allCornersValid = false;
            break;
          }
        }

        if (allCornersValid) {
          validCorrection = correction;
          break;
        }
      }

      if (validCorrection) {
        break; // Found a valid correction, no need to try larger corrections
      }
    }

    if (validCorrection) {
      player.x = newX + validCorrection.x;
      player.y = newY + validCorrection.y;
    }
  } else if (invalidCorners.length === 0) {
    // All corners are valid, move normally
    player.x = newX;
    player.y = newY;
  } else {
    // All corners are invalid, don't move
    canMove = false;
  }

  // Send position update if we moved
  if (canMove) {
    const currentTime = Date.now();
    if (currentTime - lastPositionUpdate >= POSITION_UPDATE_INTERVAL) {
      sendPositionUpdate();
      lastPositionUpdate = currentTime;
    }
  }
}

function sendPositionUpdate(): void {
  if (websocket.readyState === WebSocket.OPEN) {
    const msg = {
      position: {
        x: player.x,
        y: player.y
      }
    };
    websocket.send(JSON.stringify(msg));
  }
}

function drawGame(): void {
  // Clear canvas
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  // Draw game field
  const gameField = currentGameData?.gameField || [];
  for (let i = 0; i < gameField.length; i++) {
    for (let j = 0; j < gameField[i].length; j++) {
      const cellType = gameField[i][j];
      ctx.drawImage(image, WALL_COLORS[cellType], 48, 16, 16, j * CELL_SIZE, i * CELL_SIZE, CELL_SIZE, CELL_SIZE);
    }
  }

  // Draw player
  ctx.fillStyle = '#2196F3';
  ctx.beginPath();
  ctx.arc(
    player.x,
    player.y,
    CELL_SIZE / 2,
    0,
    Math.PI * 2
  );
  ctx.fill();

  // Draw opponents with animation
  ctx.fillStyle = '#F44336';
  currentOpponents.forEach((opponent, index) => {
    let x = opponent.x;
    let y = opponent.y;

    // Apply animation if exists
    const animation = opponentAnimations[index];
    if (animation) {
      const elapsed = Date.now() - animation.startTime;
      if (elapsed < animation.duration) {
        const progress = elapsed / animation.duration;
        x = animation.startX + (animation.endX - animation.startX) * progress;
        y = animation.startY + (animation.endY - animation.startY) * progress;
      } else {
        // Animation complete
        opponentAnimations[index] = null;
      }
    }

    ctx.beginPath();
    ctx.arc(
      x,
      y,
      CELL_SIZE / 2,
      0,
      Math.PI * 2
    );
    ctx.fill();
  });
}

function handleKeyDown(e: KeyboardEvent): void {
  switch (e.key.toLowerCase()) {
    case 'w':
    case 'arrowup':
      keys.up = true;
      break;
    case 's':
    case 'arrowdown':
      keys.down = true;
      break;
    case 'a':
    case 'arrowleft':
      keys.left = true;
      break;
    case 'd':
    case 'arrowright':
      keys.right = true;
      break;
  }
}

function handleKeyUp(e: KeyboardEvent): void {
  switch (e.key.toLowerCase()) {
    case 'w':
    case 'arrowup':
      keys.up = false;
      break;
    case 's':
    case 'arrowdown':
      keys.down = false;
      break;
    case 'a':
    case 'arrowleft':
      keys.left = false;
      break;
    case 'd':
    case 'arrowright':
      keys.right = false;
      break;
  }
}

// Call init to start everything
init();
