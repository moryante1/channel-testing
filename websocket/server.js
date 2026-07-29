const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');

const app = express();
app.use(cors());
app.use(express.json()); // For parsing JSON bodies from PHP

const server = http.createServer(app);
const io = new Server(server, {
    cors: {
        origin: "*", // allow any origin since this is an internal/broadcast service
        methods: ["GET", "POST"]
    }
});

// Broadcast endpoint: PHP backend sends updates here
app.post('/broadcast', (req, res) => {
    const { event, data, room } = req.body;
    
    if (!event) {
        return res.status(400).json({ error: 'Event name is required' });
    }

    if (room) {
        io.to(room).emit(event, data);
    } else {
        io.emit(event, data);
    }

    console.log(`[Broadcast] Event: ${event}, Room: ${room || 'global'} - Data:`, data);
    res.json({ success: true, message: 'Broadcasted successfully' });
});

io.on('connection', (socket) => {
    console.log('[WebSocket] New client connected:', socket.id);

    // Clients can join specific rooms (e.g., 'movies', 'channels')
    socket.on('join_room', (room) => {
        socket.join(room);
        console.log(`[WebSocket] Socket ${socket.id} joined room ${room}`);
    });

    socket.on('disconnect', () => {
        console.log('[WebSocket] Client disconnected:', socket.id);
    });
});

const PORT = process.env.WS_PORT || 3000;
server.listen(PORT, () => {
    console.log(`WebSocket server running on port ${PORT}`);
});
