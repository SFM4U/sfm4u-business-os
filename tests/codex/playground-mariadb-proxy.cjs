// Loopback fixture only. Preserve SQL, server status, row counts and insert IDs.
// The Playground WASM mysqlnd build misreads an omitted optional OK message.
const net = require('node:net');

const serverPort = 18942;
const proxyPort = 18947;
const encodedWidths = new Map([[0xfc, 3], [0xfd, 4], [0xfe, 9]]);

function normalize(packet) {
    const payloadLength = packet.length - 4;
    if (packet[3] !== 1 || packet[4] !== 0 || payloadLength < 7) return packet;
    const affectedRowsWidth = encodedWidths.get(packet[5]) ?? 1;
    const insertIdWidth = encodedWidths.get(packet[5 + affectedRowsWidth]) ?? 1;
    if (payloadLength !== 1 + affectedRowsWidth + insertIdWidth + 4) return packet;
    const withEmptyMessage = Buffer.concat([packet, Buffer.from([0])]);
    withEmptyMessage.writeUIntLE(payloadLength + 1, 0, 3);
    return withEmptyMessage;
}

net.createServer(client => {
    const server = net.connect(serverPort, '127.0.0.1');
    let pending = Buffer.alloc(0);
    server.on('data', chunk => {
        pending = Buffer.concat([pending, chunk]);
        while (pending.length >= 4) {
            const packetLength = pending.readUIntLE(0, 3) + 4;
            if (pending.length < packetLength) break;
            if (!client.write(normalize(pending.subarray(0, packetLength)))) server.pause();
            pending = pending.subarray(packetLength);
        }
    });
    client.on('drain', () => server.resume());
    client.pipe(server);
    server.on('end', () => client.end());
    client.on('close', () => server.destroy());
    server.on('close', () => client.destroy());
    client.on('error', () => server.destroy());
    server.on('error', () => client.destroy());
}).listen(proxyPort, '127.0.0.1');
