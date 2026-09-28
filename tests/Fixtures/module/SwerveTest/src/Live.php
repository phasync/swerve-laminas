<?php

namespace SwerveTest;

/** WebSocket callbacks running in this worker, so that the tests see every one end. */
final class Live
{
    public static int $callbacks = 0;
}
